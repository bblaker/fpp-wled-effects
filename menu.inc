<?php
# Menu entries for fpp-wled-effects (at most one per menu area).
#
# FPP sets $plugin (this plugin's directory name) and $menu (the menu area
# being drawn) before including this file.

$menuEntries = Array(
	Array(
		'text' => 'WLED Effects',
		'type' => 'content',
		'page' => 'wled.php',
		'wrap' => 1
	),
	Array(
		'text' => 'WLED Effects',
		'type' => 'help',
		'page' => 'help.php',
		'wrap' => 1
	)
);

foreach ($menuEntries as $entry)
{
	if ($entry['type'] != $menu)
		continue;

	$nopage = '';
	if (isset($entry['wrap']) && ($entry['wrap'] == 0))
		$nopage = '&nopage=1';

	printf("<li><a href='plugin.php?plugin=%s&page=%s%s'>%s</a></li>\n",
		$plugin, $entry['page'], $nopage, $entry['text']);
}
?>
