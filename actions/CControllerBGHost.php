<?php declare(strict_types = 1);

/*
** Zabbix
** Copyright (C) 2001-2021 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/

namespace Modules\BGmotHosts\Actions;

use CController;
use CSettingsHelper;
use API;
use CArrayHelper;
use CUrl;
use CPagerHelper;
use CApiHostHelper;
use CWebUser;

abstract class CControllerBGHost extends CController {

	// Filter idx prefix.
	const FILTER_IDX = 'web.monitoring.bghosts';

	// Filter fields default values.
	const FILTER_FIELDS_DEFAULT = [
		'name' => '',
		'groupids' => [],
		'ip' => '',
		'dns' => '',
		'port' => '',
		'status' => -1,
		'evaltype' => TAG_EVAL_TYPE_AND_OR,
		'tags' => [],
		'severities' => [],
		'show_suppressed' => ZBX_PROBLEM_SUPPRESSED_FALSE,
		'maintenance_status' => HOST_MAINTENANCE_STATUS_ON,
		'page' => null,
		'sort' => 'name',
		'sortorder' => ZBX_SORT_UP,
		'expanded_groups' => []
	];

	/**
	 * Prepares the host list based on the given filter and sorting options.
	 *
	 * @param array  $filter                        Filter options.
	 * @param string $filter['name']                Filter hosts by name.
	 * @param array  $filter['groupids']            Filter hosts by host groups.
	 * @param string $filter['ip']                  Filter hosts by IP.
	 * @param string $filter['dns']	                Filter hosts by DNS.
	 * @param string $filter['port']                Filter hosts by port.
	 * @param string $filter['status']              Filter hosts by status.
	 * @param string $filter['evaltype']            Filter hosts by tags.
	 * @param string $filter['tags']                Filter hosts by tag names and values.
	 * @param string $filter['severities']          Filter problems on hosts by severities.
	 * @param string $filter['show_suppressed']     Filter suppressed problems.
	 * @param int    $filter['maintenance_status']  Filter hosts by maintenance.
	 * @param int    $filter['page']                Page number.
	 * @param string $filter['sort']                Sorting field.
	 * @param string $filter['sortorder']           Sorting order.
	 *
	 * @return array
	 */
	protected function getData(array $filter, array $expanded_groups): array {
		$rows_per_page = (int)CWebUser::$data['rows_per_page'];

		$groupids = $filter['groupids'] ? getSubGroups($filter['groupids']) : null; // Groups from filter
		$groups = API::HostGroup()->get([
			'output' => ['groupid', 'name'],
			'groupids' => $groupids,
			'with_monitored_hosts' => true,
			'sortfield' => 'name'
		]);

		// Build host groups dictionary $host_groups_dict[<group_name>] = <group_id>
		$host_groups_dict = [];
		foreach ($groups as $group) {
			$host_groups_dict[$group['name']] = $group['groupid'];
		}

		$host_groups = [];
		$fake_group_id = 100000;

		foreach ($groups as $group) {
			$groupname_full = $group['name'];
			$groupid = $group['groupid'];

			// Find number of hosts
			$hc = API::Host()->get([
				'groupids' => $group['groupid'],
				'countOutput' => true
			]);

			if (!array_key_exists($groupname_full, $host_groups)) {
				$host_groups[$groupname_full] = [
					'groupid' => $groupid,
					'hosts' => [],
					'children' => [],
					'parent_group_name' => '',
					'num_of_hosts' => $hc,
					'problem_count' => [],
					'is_collapsed' => in_array($groupid, $expanded_groups) ? false : true
				];
				for ($severity = TRIGGER_SEVERITY_COUNT - 1; $severity >= TRIGGER_SEVERITY_NOT_CLASSIFIED; $severity--) {
					$host_groups[$groupname_full]['problem_count'][$severity] = 0;
				}
				$grp_arr = explode('/', $groupname_full);
				if (count($grp_arr) > 1) {
					// Find all parent groups and create respective array elements in $host_groups
					$this->add_parent($host_groups, $host_groups_dict, $fake_group_id, $groupname_full, $filter, $expanded_groups);
				}
			}
		}
		unset($group);

		// Calculate total number of hosts in the groups with subgroups
		// Collapse all child groups if parent group is collapsed
		foreach ($host_groups as $group_name => $group_data) {
			if ($group_data['parent_group_name'] == '' && $group_data['children']) {
				foreach ($group_data['children'] as $subgroup_name)
					$this->group_add_num_of_hosts_from_child($host_groups, $group_name, $subgroup_name);
			}
		}

		// Calculate problem counts for all groups (including collapsed ones)
		$this->calculateGroupProblems($host_groups, $filter);
		$hosts_from_all_groups = [];
		$total_number_of_hosts = 0;

		foreach ($host_groups as $group_name => $group_data) {
			if (
				$group_data['parent_group_name'] != '' || // Child groups will be added recursively
				$group_data['is_collapsed'] // The group is collapsed, do not pull hosts for collapsed groups
				)
				continue;

			$hosts_of_this_group = $this->get_hosts_for_group($host_groups, $group_name, $group_data, $filter);
			$hosts_from_all_groups += $hosts_of_this_group;
		}

		// Split result array and create paging.
		$view_curl = (new CUrl())->setArgument('action', 'bghost.view');
		if ($expanded_groups)
			$view_curl->setArgument('expanded_groups', implode(',', $expanded_groups));

		$paging_arguments = array_filter(array_intersect_key($filter, self::FILTER_FIELDS_DEFAULT));

		array_map([$view_curl, 'setArgument'], array_keys($paging_arguments), $paging_arguments);

		// Split result array and create paging.
		$paging = CPagerHelper::paginate($filter['page'], $hosts_from_all_groups, $filter['sortorder'], $view_curl);

		// After paging we have only hosts to show in $hosts_from_all_groups
		$hosts_from_all_groups_hostid_as_key = [];
		$groups_for_hosts_from_all_groups = []; // List of groups hosts to show belong to
		foreach ($hosts_from_all_groups as $host) {
			$hosts_from_all_groups_hostid_as_key[$host['hostid']] = $host;
			$groups_for_hosts_from_all_groups = array_merge($groups_for_hosts_from_all_groups, $host['group_names']);
		}
		$groups_for_hosts_from_all_groups = array_unique($groups_for_hosts_from_all_groups);

		/*
		// Remove child groups that do not have hosts to show on > 1 pages
		$groups_to_show = $host_groups;
		foreach ($host_groups as $group_name => $group_data) {
			$parent_group_name = $group_data['parent_group_name'];
			if (
				$parent_group_name != '' && // Child group
				$filter['page'] > 1 && // Page > 1
				array_search($group_name, $groups_for_hosts_from_all_groups) === false // Does not have hosts to show
				) {
				if (array_key_exists($parent_group_name, $groups_to_show)) {
					$index = array_search($group_name, $groups_to_show[$parent_group_name]['children']);
					unset($groups_to_show[$parent_group_name]['children'][$index]);
				}
				unset($groups_to_show[$group_name]);
			}
		}
		$host_groups = $groups_to_show;
		unset($groups_to_show);
		*/
		return [
			'paging' => $paging,
			'hosts' => $hosts_from_all_groups_hostid_as_key,
			'host_groups' => $host_groups,
			'maintenances' => []
		];
	}

	protected function get_hosts_for_group(&$groups, $group_name, $group_data, $filter) {
		$groupid = $group_data['groupid'];
		$search_limit = CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT) + 1;
		$hosts_of_sub_group = [];
		if ($group_data['children']) {
			foreach ($group_data['children'] as $g_name) {
				if (array_key_exists($g_name, $groups)) { // The child group was not removed from "visible" on > 1 pages
					if ($groups[$g_name]['is_collapsed']) // The group is collapsed
						continue;
					return $this->get_hosts_for_group($groups, $g_name, $groups[$g_name], $filter);
				}
			}
		} else if (!array_key_exists('hosts', $group_data)) {
			return [];
		}

		$hosts = API::Host()->get([
			'output' => ['hostid', 'name', 'status'],
			'evaltype' => $filter['evaltype'],
			'tags' => $filter['tags'],
			'inheritedTags' => true,
			'groupids' => $groupid,
			'severities' => $filter['severities'] ? $filter['severities'] : null,
			'selectInterfaces' => ['ip', 'dns', 'port', 'main', 'type', 'useip', 'available', 'error', 'details'],
			'selectGraphs' => API_OUTPUT_COUNT,
			'selectHttpTests' => API_OUTPUT_COUNT,
			'selectTags' => ['tag', 'value'],
			'selectInheritedTags' => ['tag', 'value'],
			'withProblemsSuppressed' => $filter['severities']
				? (($filter['show_suppressed'] == ZBX_PROBLEM_SUPPRESSED_TRUE) ? null : false)
				: null,
			'search' => [
				'name' => ($filter['name'] === '') ? null : $filter['name'],
				'ip' => ($filter['ip'] === '') ? null : $filter['ip'],
				'dns' => ($filter['dns'] === '') ? null : $filter['dns']
			],
			'filter' => [
				'status' => ($filter['status'] == -1) ? null : $filter['status'],
				'port' => ($filter['port'] === '') ? null : $filter['port'],
				'maintenance_status' => ($filter['maintenance_status'] == HOST_MAINTENANCE_STATUS_ON)
					? null
					: HOST_MAINTENANCE_STATUS_OFF
			],
			'sortfield' => 'name',
			'preservekeys' => true,
			'limit' => $search_limit
		]);

		$hosts = $this->array_sort($hosts, 'name', $filter['sortorder']);

		$host_ids = array_column($hosts, 'hostid');
		$groups[$group_name]['hosts'] = $host_ids;

		// Select triggers and problems to calculate number of problems for each host.
		$triggers = API::Trigger()->get([
			'output' => [],
			'selectHosts' => ['hostid'],
			'hostids' => $host_ids,
			'skipDependent' => true,
			'monitored' => true,
			'preservekeys' => true
		]);

		$problems = API::Problem()->get([
			'output' => ['eventid', 'objectid', 'severity'],
			'objectids' => array_keys($triggers),
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'suppressed' => ($filter['show_suppressed'] == ZBX_PROBLEM_SUPPRESSED_TRUE) ? null : false
		]);

		// Group all problems per host per severity.
		$host_problems = [];
		foreach ($problems as $problem) {
			foreach ($triggers[$problem['objectid']]['hosts'] as $trigger_host) {
				$host_problems[$trigger_host['hostid']][$problem['severity']][$problem['eventid']] = true;
			}
		}

		// Get number of monitored items for each host
		$items_count = API::Item()->get([
			'countOutput' => true,
			'groupCount' => true,
			'hostids' => $host_ids,
			'webitems' =>true,
			'monitored' => true
		]);
		$items_count = $items_count ? array_column($items_count, 'rowscount', 'hostid') : [];

		// Count problems for the group - take into account only hosts belonging to group (no parents/children)
		// Add other properties (items_count etc) to each host
		foreach($hosts as &$host_data) {
			// Save group name this host belong to
			if (array_key_exists('group_names', $host_data))
				$host_data['group_names'][] = $group_name;
			else
				$host_data['group_names'] = [ $group_name ];

			$hostid = $host_data['hostid'];

			// Count the number of problems (as value) per severity (as key).
			for ($severity = TRIGGER_SEVERITY_COUNT - 1; $severity >= TRIGGER_SEVERITY_NOT_CLASSIFIED; $severity--) {
				// Fill empty arrays for hosts without problems.
				if (array_key_exists($hostid, $host_problems)) {
					if (array_key_exists($severity, $host_problems[$hostid])) {
						$host_data['problem_count'][$severity] = count($host_problems[$hostid][$severity]);
						// Note: Don't add to group problem_count here as it's already calculated in calculateGroupProblems
					}
				} else {
					$host_data['problem_count'][$severity] = 0;
				}
			}

			// Add number of items
			$host_data['items_count'] = array_key_exists($hostid, $items_count) ? $items_count[$hostid] : 0;

			// Count number of dashboards for each host.
			$templateids = CApiHostHelper::getParentTemplates([$hostid])[1];
			$host_data['dashboards'] = count(API::TemplateDashboard()->get([
				'output' => ['dashboardid'],
				'templateids' => $templateids,
				'preservekeys' => true
			]));

			// Merge host tags with template tags, and skip duplicate tags and values.
			if (!$host_data['inheritedTags']) {
				$tags = $host_data['tags'];
			}
			elseif (!$host_data['tags']) {
				$tags = $host_data['inheritedTags'];
			}
			else {
				$tags = $host_data['tags'];

				foreach ($host_data['inheritedTags'] as $template_tag) {
					foreach ($tags as $host_tag) {
						// Skip tags with same name and value.
						if ($host_tag['tag'] === $template_tag['tag']
								&& $host_tag['value'] === $template_tag['value']) {
							continue 2;
						}
					}
					$tags[] = $template_tag;
				}
			}
			$host_data['tags'] = $tags;
		} // for all hosts in the group

		$tags = makeTags($hosts, true, 'hostid', ZBX_TAG_COUNT_DEFAULT, $filter['tags']);
		foreach ($hosts as &$host) {
			$host['tags'] = $tags[$host['hostid']];
		}

		return $hosts_of_sub_group + $hosts;
	}

	protected function group_add_num_of_hosts_from_child(&$host_groups, $group_name, $subgroup_name) {
		if ($host_groups[$subgroup_name]['children']) {
			foreach ($host_groups[$subgroup_name]['children'] as $sub_subgroup_name) {
				$this->group_add_num_of_hosts_from_child($host_groups, $subgroup_name, $sub_subgroup_name);
			}
		}
		$host_groups[$group_name]['num_of_hosts'] += $host_groups[$subgroup_name]['num_of_hosts'];
	}

	/**
	 * Adds parent group
	 *
	 * @param array $host_groups      All the groups to be shown in hierarchy
	 * @param int   $fake_group_id    ID for groups that do not exist in Zabbix DB (autoincremented)
	 * @param string $groupname_full  Group name parent group of which needs to be added
	 * @param array  $filter          Filter options.
	 *
	 * @return array $host_groups modified in-place
	 */
	protected function add_parent(&$host_groups, $host_groups_dict, &$fake_group_id, $groupname_full, $filter, $expanded_groups) {
		// There is a '/' in group name
		$grp_arr = explode('/', $groupname_full);
		unset($grp_arr[count($grp_arr)-1]); // Remove last element
		$parent_group_name = implode('/', $grp_arr);
		// In Zabbix it is possible to have parent name that does not exist
		// e.g.: group '/level0/level1/level2' exists but '/level0/level1' does not
		if (array_key_exists($parent_group_name, $host_groups)) {
			// Parent group exists
			if (!in_array($groupname_full, $host_groups[$parent_group_name]['children'])) {
				$host_groups[$parent_group_name]['children'][] = $groupname_full;
			}
		} else {
			// Parent group does not exist or does not have any hosts to show
			$groupid = in_array($parent_group_name, $host_groups_dict) ? $host_groups_dict[$parent_group_name] : $fake_group_id;
			$host_groups[$parent_group_name] = [
				'groupid' => $groupid,
				'hosts' => [],
				'children' => [$groupname_full],
				'parent_group_name' => '',
				'num_of_hosts' => 0,
				'problem_count' => [],
				'is_collapsed' => in_array($groupid, $expanded_groups) ? false : true
			];
			if (!in_array($parent_group_name, $host_groups_dict)) {
				// One fake group id was used, increment it for the next one
				$fake_group_id++;
			}
			for ($severity = TRIGGER_SEVERITY_COUNT - 1; $severity >= TRIGGER_SEVERITY_NOT_CLASSIFIED; $severity--) {
				$host_groups[$parent_group_name]['problem_count'][$severity] = 0;
			}
		}
		$host_groups[$groupname_full]['parent_group_name'] = $parent_group_name;
		// Sort group names
		$filter['sortorder'] == 'ASC' ? sort($host_groups[$parent_group_name]['children']) : rsort($host_groups[$parent_group_name]['children']);
	}

	protected function array_sort($array, $on, $order='ASC')
	{
		$new_array = array();
		$sortable_array = array();

		if (count($array) > 0) {
			foreach ($array as $k => $v) {
				if (is_array($v)) {
					foreach ($v as $k2 => $v2) {
						if ($k2 == $on) {
							$sortable_array[$k] = $v2;
						}
					}
				} else {
					$sortable_array[$k] = $v;
				}
			}
			switch ($order) {
				case 'ASC':
					asort($sortable_array, SORT_NATURAL);
					break;
				case 'DESC':
					arsort($sortable_array, SORT_NATURAL);
					break;
			}
			foreach ($sortable_array as $k => $v) {
				$new_array[$k] = $array[$k];
			}
		}

		return $new_array;
	}

	/**
	 * Get additional data for filters. Selected groups for multiselect, etc.
	 *
	 * @param array $filter  Filter fields values array.
	 *
	 * @return array
	 */
	protected function getAdditionalData($filter): array {
		$data = [];

		if ($filter['groupids']) {
			$groups = API::HostGroup()->get([
				'output' => ['groupid', 'name'],
				'groupids' => $filter['groupids']
			]);
			$data['groups_multiselect'] = CArrayHelper::renameObjectsKeys(array_values($groups), ['groupid' => 'id']);
		}

		return $data;
	}

	/**
	 * Clean passed filter fields in input from default values required for HTML presentation. Convert field
	 *
	 * @param array $input  Filter fields values.
	 *
	 * @return array
	 */
	protected function cleanInput(array $input): array {
		if (array_key_exists('tags', $input) && $input['tags']) {
			$input['tags'] = array_filter($input['tags'], function($tag) {
				return !($tag['tag'] === '' && $tag['value'] === '');
			});
			$input['tags'] = array_values($input['tags']);
		}

		return $input;
	}

	/**
	 * Calculate problem counts for all groups
	 *
	 * @param array $host_groups All host groups data
	 * @param array $filter      Filter options
	 *
	 * @return void
	 */
	protected function calculateGroupProblems(array &$host_groups, array $filter): void {
		// First, calculate problems for ALL groups (including their direct hosts)
		foreach ($host_groups as $group_name => &$group) {
			$groupid = $group['groupid'];
			
			// Get DIRECT hosts for this specific group (not from child groups)
			$hosts = API::Host()->get([
				'output' => ['hostid'],
				'groupids' => [$groupid],
				'evaltype' => $filter['evaltype'],
				'tags' => $filter['tags'],
				'inheritedTags' => true,
				'search' => [
					'name' => ($filter['name'] === '') ? null : $filter['name'],
					'ip' => ($filter['ip'] === '') ? null : $filter['ip'],
					'dns' => ($filter['dns'] === '') ? null : $filter['dns']
				],
				'filter' => [
					'status' => ($filter['status'] == -1) ? null : $filter['status'],
					'port' => ($filter['port'] === '') ? null : $filter['port'],
					'maintenance_status' => ($filter['maintenance_status'] == HOST_MAINTENANCE_STATUS_ON)
						? null
						: HOST_MAINTENANCE_STATUS_OFF
				]
			]);

			if (empty($hosts)) {
				continue;
			}

			$host_ids = array_column($hosts, 'hostid');

			// Get triggers for this group's direct hosts
			$triggers = API::Trigger()->get([
				'output' => [],
				'hostids' => $host_ids,
				'skipDependent' => true,
				'monitored' => true,
				'preservekeys' => true
			]);

			if (empty($triggers)) {
				continue;
			}

			// Get problems for this group's triggers
			$problems = API::Problem()->get([
				'output' => ['severity'],
				'objectids' => array_keys($triggers),
				'source' => EVENT_SOURCE_TRIGGERS,
				'object' => EVENT_OBJECT_TRIGGER,
				'suppressed' => ($filter['show_suppressed'] == ZBX_PROBLEM_SUPPRESSED_TRUE) ? null : false
			]);

			// Count problems by severity for this group's direct hosts
			foreach ($problems as $problem) {
				$severity = $problem['severity'];
				$group['problem_count'][$severity]++;
			}
		}
		unset($group);

		// Now propagate problem counts from children to parents  
		// Process from bottom to top (deepest children first)
		$this->propagateChildProblemsToParents($host_groups);
	}

	/**
	 * Propagate child group problems to parent groups from bottom-up
	 *
	 * @param array $host_groups All host groups data
	 *
	 * @return void
	 */
	protected function propagateChildProblemsToParents(array &$host_groups): void {
		// Find groups by depth level to process bottom-up
		$groups_by_depth = [];
		foreach ($host_groups as $group_name => $group) {
			$depth = substr_count($group_name, '/');
			$groups_by_depth[$depth][] = $group_name;
		}
		
		// Sort by depth (deepest first)
		krsort($groups_by_depth);
		
		// Process from deepest to shallowest
		foreach ($groups_by_depth as $depth => $groups_at_depth) {
			foreach ($groups_at_depth as $group_name) {
				if (!empty($host_groups[$group_name]['children'])) {
					// Add all children's problems to this parent
					foreach ($host_groups[$group_name]['children'] as $child_group_name) {
						if (isset($host_groups[$child_group_name])) {
							foreach ($host_groups[$child_group_name]['problem_count'] as $severity => $count) {
								$host_groups[$group_name]['problem_count'][$severity] += $count;
							}
						}
					}
				}
			}
		}
	}
}