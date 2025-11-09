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


/**
 * @var CView $this
 */

?>

<script type="text/javascript">
	// Transfer information about groups from PHP into JavaScript data Object
	var groups = [];
	var data = <?php
		echo '{';
		foreach ($data['host_groups'] as $group_name => $group) {
			//if (array_key_exists('children', $group)) {
			//	if (count($group['children']) > 0) {
					echo "'".$group['groupid']."':[";
					print_children($data, $group);
					echo "],";
			//	}
			//}
		}
		echo '}';
		function print_children($data, $group) {
			$num_of_children = count($group['children']);
			$index = 0;
			foreach($group['children'] as $child_group_name) {
				$child_group_id = $data['host_groups'][$child_group_name]['groupid'];

				echo "'" . $child_group_id . "'";
				if ($index < $num_of_children-1) {
					echo ',';
				}

				$index++;
			}
		} ?>;

	function isChevronCollapsed($chevron) {
		return $chevron.hasClass('<?= ZBX_STYLE_ARROW_RIGHT ?>');
	}

	$('.js-toggle').on('click', function() {
		var $toggle = $(this),
			collapsed = !isChevronCollapsed($toggle.find('span'));
		var group_id = 0;
		for (const key in $toggle[0].attributes) {
			var attr = $toggle[0].attributes[key];
			if (attr.name.startsWith('data-')) {
				group_id = attr.value
				break;
			}
		}
		if (!collapsed) {
			var parent_group_id = 0;
			var parents = {}; // Key=group_id, Value=parent_group_id or 0 if root level
			// Expanding, find parent group
			for (var gid in data) {
				if (!(gid in parents))
					parents[gid] = 0;
				for (var j = 0; j < data[gid].length; j++) // For all children for given group
					parents[data[gid][j]] = gid;

				if (data[gid].indexOf(group_id) > -1) { // This group is a child of data[i] group
					parent_group_id = data[gid];
					// Hide all other children
					for (var j in data[gid]) {
						var child_group_id = data[gid][j];
						if (child_group_id != group_id)
							view.groupToFromRefreshUrl(child_group_id, true);
					}
				}
			}

			if ( parent_group_id == 0) {
				// Root level group collapsed, hide all other root level groups
				for (var gid in parents)
					if (parents[gid] == 0 && // Root level
						gid != group_id)
						view.groupToFromRefreshUrl(gid, true);
			}
		}

		view.groupToFromRefreshUrl(group_id, collapsed);

		view.refresh();
	});
</script>