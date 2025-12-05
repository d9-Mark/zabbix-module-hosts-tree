<?php
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

$this->includeJsFile('monitoring.host.view.refresh.js.php');

$form = (new CForm())->setName('host_view');

$table = (new CTableInfo());

$view_url = $data['view_curl']->getUrl();

$at_least_one_host_to_show = false;
$paging_line_added = false;

$table->setHeader([
	make_sorting_header(_('Name'), 'name', $data['sort'], $data['sortorder'], $view_url),
	(new CColHeader(_('Interface'))),
	(new CColHeader(_('Availability'))),
	(new CColHeader(_('Tags'))),
	// Fix: problems renamed to triggers to distinguish from the problems counter column
	make_sorting_header(_('Status'), 'status', $data['sort'], $data['sortorder'], $view_url),
	(new CColHeader(_('Latest data'))),
	(new CColHeader(_('Problems'))),
	(new CColHeader(_('Graphs'))),
	(new CColHeader(_('Dashboards'))),
	(new CColHeader(_('Web')))
]);

foreach ($data['host_groups'] as $group_name => $group) {
	if ($group['parent_group_name'] == '') {
		// Add only top level groups, children will be added recursively in addGroupRow()
		$rows = [];
		addGroupRow($data, $rows, $group_name, '', 0, $child_stat, $at_least_one_host_to_show, $paging_line_added, $form, $table);
		foreach ($rows as $row) {
			$table->addRow($row);
		}
	}
}

if (!$paging_line_added)
	$form->addItem([$table, $data['paging']]);
else
	$form->addItem($table);

echo $form;

// Adds one Group to the table (recursively calls itself for all sub-groups)
function addGroupRow($data, &$rows, $group_name, $parent_group_name, $level, &$child_stat, &$at_least_one_host_to_show, &$paging_line_added, &$form, &$table) {

	$interface_types = [INTERFACE_TYPE_AGENT, INTERFACE_TYPE_SNMP, INTERFACE_TYPE_JMX, INTERFACE_TYPE_IPMI];

	$group = $data['host_groups'][$group_name];

	$host_rows = [];
	foreach ($group['hosts'] as $hostid) {
		if (!array_key_exists($hostid, $data['hosts']))
			continue;

		$at_least_one_host_to_show = true;

		$host = $data['hosts'][$hostid];
		$host_name = (new CLinkAction($host['name']))->setMenuPopup(CMenuPopupHelper::getHost($hostid));
		$interface = null;
		if ($host['interfaces']) {
                        foreach ($host['interfaces'] as $index => $value) {
                            $host['interfaces'][$index]['has_enabled_items'] = true;
                        }
			foreach ($interface_types as $interface_type) {
				$host_interfaces = array_filter($host['interfaces'], function(array $host_interface) use ($interface_type) {
					return ($host_interface['type'] == $interface_type);
				});
				if ($host_interfaces) {
					$interface = reset($host_interfaces);
					break;
				}
			}
		}

		$problems_link = new CLink('', (new CUrl('zabbix.php'))
			->setArgument('action', 'problem.view')
			->setArgument('filter_name', '')
			->setArgument('severities', $data['filter']['severities'])
			->setArgument('hostids', [$host['hostid']]));

		$total_problem_count = 0;

		// Fill the severity icons by problem count and style, and calculate the total number of problems.
		// Need this to have cosntant order of triggers from disater to information.
		krsort($host['problem_count'],true);

		foreach ($host['problem_count'] as $severity => $count) {
			if (($count > 0 && $data['filter']['severities'] && in_array($severity, $data['filter']['severities']))
					|| (!$data['filter']['severities'] && $count > 0)) {
				$total_problem_count += $count;

				$problems_link->addItem((new CSpan($count))
					->addClass(ZBX_STYLE_PROBLEM_ICON_LIST_ITEM)
					->addClass(CSeverityHelper::getStatusStyle($severity))
					->setAttribute('title', CSeverityHelper::getName($severity))
				);
			}
		}

		if ($total_problem_count == 0) {
			$problems_link->addItem('Problems');
		}
		else {
			$problems_link->addClass(ZBX_STYLE_PROBLEM_ICON_LINK);
		}

		$col1 = new CCol();
		for($i = 0; $i <= (6 + $level*5); $i++) {
			$col1 -> addItem(NBSP_BG());
		}
		$col1 -> addItem($host_name);
		$table_row_host = new CRow([
			$col1,
			(new CCol(getHostInterface($interface)))->addClass(ZBX_STYLE_NOWRAP),
			getHostAvailabilityTable($host['interfaces']),
			$host['tags'],
			($host['status'] == HOST_STATUS_MONITORED)
				? (new CSpan(_('Enabled')))->addClass(ZBX_STYLE_GREEN)
				: (new CSpan(_('Disabled')))->addClass(ZBX_STYLE_RED),
			[
				$data['allowed_ui_latest_data']
					? new CLink(_('Latest data'),
						(new CUrl('zabbix.php'))
							->setArgument('action', 'latest.view')
							->setArgument('filter_set', '1')
							->setArgument('hostids', [$host['hostid']])
					)
					: (new CSpan(_('Latest data')))->addClass(ZBX_STYLE_DISABLED),
                    CViewHelper::showNum($host['items_count'])
			],
			$problems_link,
			$host['graphs']
				? [
					new CLink(_('Graphs'),
						(new CUrl('zabbix.php'))
							->setArgument('action', 'charts.view')
							->setArgument('filter_set', '1')
							->setArgument('filter_hostids', (array) $host['hostid'])
					),
					CViewHelper::showNum($host['graphs'])
				]
				: (new CSpan(_('Graphs')))->addClass(ZBX_STYLE_DISABLED),
			$host['dashboards']
				? [
					new CLink(_('Dashboards'),
						(new CUrl('zabbix.php'))
							->setArgument('action', 'host.dashboard.view')
							->setArgument('hostid', $host['hostid'])
					),
					CViewHelper::showNum($host['dashboards'])
				]
				: (new CSpan(_('Dashboards')))->addClass(ZBX_STYLE_DISABLED),
			$host['httpTests']
				? [
					new CLink(_('Web'),
						(new CUrl('zabbix.php'))
							->setArgument('action', 'web.view')
							->setArgument('filter_set', '1')
							->setArgument('filter_hostids', (array) $host['hostid'])
					),
					CViewHelper::showNum($host['httpTests'])
				]
				: (new CSpan(_('Web')))->addClass(ZBX_STYLE_DISABLED)
		]);

		if ($data['host_groups'][$group_name]['is_collapsed'] )
			$table_row_host->addClass(ZBX_STYLE_DISPLAY_NONE);

		addParentGroupClass($data, $table_row_host, $group_name);
		$host_rows[] = $table_row_host;
	}

	if ($at_least_one_host_to_show && !$paging_line_added) {
		$paging_line_added = true;
		$col2 = (new CCol()) -> setColSpan(11);
		$col2 -> addItem($data['paging']);
		$paging_row = (new CRow([$col2]))->setAttribute('data-group_id_27', 27);
		$host_rows[] = $paging_row;
	}

	$subgroup_rows=[];

	foreach ($data['host_groups'][$group_name]['children'] as $child_group_name) {
		addGroupRow($data, $subgroup_rows, $child_group_name, $group_name, $level + 1, $my_stat, $at_least_one_host_to_show, $paging_line_added, $form, $table);
	}


	$is_collapsed = $data['host_groups'][$group_name]['is_collapsed'];
	$toggle_tag = (new CSimpleButton())
		->addClass(ZBX_STYLE_TREEVIEW)
		->addClass('js-toggle')
		->addItem(
			(new CSpan())->addClass($is_collapsed ? ZBX_STYLE_ARROW_RIGHT : ZBX_STYLE_ARROW_DOWN)
	);
	$toggle_tag->setAttribute(
		'data-group_id_'.$data['host_groups'][$group_name]['groupid'],
		$data['host_groups'][$group_name]['groupid']
	);

	$group_name_arr = explode('/', $group_name);

	$group_problems_div = (new CDiv())->addClass(ZBX_STYLE_PROBLEM_ICON_LIST);

	foreach ($data['host_groups'][$group_name]['problem_count'] as $severity => $count) {
		if (($count > 0 && $data['filter']['severities'] && in_array($severity, $data['filter']['severities']))
				|| (!$data['filter']['severities'] && $count > 0)) {
			$group_problems_div->addItem((new CSpan($count))
				->addClass(ZBX_STYLE_PROBLEM_ICON_LIST_ITEM)
				->addClass(CSeverityHelper::getStatusStyle($severity))
				->setAttribute('title', CSeverityHelper::getName($severity))
			);
		}
	}

	$col2 = (new CCol())
		-> setColSpan(4);
        for ($i = 0; $i < $level*5; $i++) {
		$col2 -> addItem(NBSP_BG());
	}
	$col2 -> addItem($toggle_tag);


	$expanded_groups = [$data['host_groups'][$group_name]['groupid']];
	// Build all expanded groups if this group to be expanded (for URL)
	$p_g_name = $data['host_groups'][$group_name]['parent_group_name'];
	if ($p_g_name != '')
		$expanded_groups = array_merge(add_expanded_parent_group($data, $group_name), $expanded_groups);

	// Calculate total problem count for this group
	$total_problem_count = 0;
	foreach ($data['host_groups'][$group_name]['problem_count'] as $severity => $count) {
		if (($count > 0 && $data['filter']['severities'] && in_array($severity, $data['filter']['severities']))
				|| (!$data['filter']['severities'] && $count > 0)) {
			$total_problem_count += $count;
		}
	}

	$col2 -> addItem(
		bold(
			new CLink(end($group_name_arr), (new CUrl('zabbix.php'))
				->setArgument('action', 'bghost.view')
				->setArgument('expanded_groups', implode(',', $expanded_groups))
				->setArgument('status', $data['filter']['severities'])
				->setArgument('maintenance_status', $data['filter']['maintenance_status'])
				->setArgument('sort', $data['filter']['sort'])
				->setArgument('sortorder', $data['filter']['sortorder']))
		)
	);

	$col2 -> addItem(NBSP_BG());
	$col2 -> addItem(bold('(' . $data['host_groups'][$group_name]['num_of_hosts']. ')'));

	// Add problem count next to group name if group is collapsed and has problems
	if ($data['host_groups'][$group_name]['is_collapsed'] && $total_problem_count > 0) {
		$col2 -> addItem(NBSP_BG());
		$col2 -> addItem((new CSpan('Problems: ' . $total_problem_count))
			->addClass('text-warning')
		);
	}
	$table_row = new CRow([
		$col2,
		$group_problems_div,
		(new CCol())
			-> setColSpan(6)
	]);

	if ($data['host_groups'][$group_name]['is_collapsed'] && 
		$parent_group_name != '' &&
		$data['host_groups'][$parent_group_name]['is_collapsed'])
		$table_row->addClass(ZBX_STYLE_DISPLAY_NONE);

	// We don't render here, but just add rows to the array
	addParentGroupClass($data, $table_row, $parent_group_name);

	$rows[] = $table_row;

	// Now all subgroup rows
	foreach ($subgroup_rows as $idx=>$row) {
		$rows[] = $row;
	}

	// And finally, the hosts rows
	foreach ($host_rows as $idx=>$row) {
		$rows[] = $row;
	}
}

// Adds class 'data-group_id_<group_id>=<group_id>' to $element
function addParentGroupClass($data, &$element, $parent_group_name) {
	if ($parent_group_name != '') {
		$element->setAttribute(
			'data-group_id_'.$data['host_groups'][$parent_group_name]['groupid'],
			$data['host_groups'][$parent_group_name]['groupid']
		);
	}
}

function add_expanded_parent_group($data, $group_name) {
	$expanded = [];
	$parent_group_name = $data['host_groups'][$group_name]['parent_group_name'];

	if ( $parent_group_name != '' &&
		array_key_exists($parent_group_name, $data['host_groups']) ) {
		$p_g_id = $data['host_groups'][$parent_group_name]['groupid'];
		$expanded = [$p_g_id];
		$expanded = array_merge(add_expanded_parent_group($data, $parent_group_name), $expanded);
	}

	return $expanded;
}

function NBSP_BG() {
	return new CHtmlEntityBG('&nbsp;');
}

class CHtmlEntityBG {
	private $entity = '';
	public function __construct(string $entity) {
		$this->entity = $entity;
	}
	public function toString(): string {
		return $this->entity;
	}
}
