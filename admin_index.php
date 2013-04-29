<?php

/**
 * Copyright (C) 2013 ModernBB
 * Based on code by FluxBB copyright (C) 2008-2012 FluxBB
 * Based on code by Rickard Andersson copyright (C) 2002-2008 PunBB
 * License: http://www.gnu.org/licenses/gpl.html GPL version 3 or higher
 */

// Tell header.php to use the admin template
define('PUN_ADMIN_CONSOLE', 1);

define('PUN_ROOT', dirname(__FILE__).'/');
require PUN_ROOT.'include/common.php';
require PUN_ROOT.'include/common_admin.php';


if (!$pun_user['is_admmod'])
	message($lang_common['No permission'], false, '403 Forbidden');

// Load the admin_index.php language file
require PUN_ROOT.'lang/'.$admin_language.'/admin_index.php';

$action = isset($_GET['action']) ? $_GET['action'] : null;

// Check for upgrade
if ($action == 'check_upgrade')
{
	if (!ini_get('allow_url_fopen'))
		message($lang_admin_index['fopen disabled message']);

	$latest_version = trim(@file_get_contents('http://modernbb.be/latest_version'));
	if (empty($latest_version))
		message($lang_admin_index['Upgrade check failed message']);

	if (version_compare($pun_config['o_cur_version'], $latest_version, '>='))
		message($lang_admin_index['Running latest version message']);
	else
		message(sprintf($lang_admin_index['New version available message'], '<a href="http://modernbb.be/">ModernBB.be</a>'));
}

$page_title = array(pun_htmlspecialchars($pun_config['o_board_title']), $lang_admin_common['Admin'], $lang_admin_common['Index']);
define('PUN_ACTIVE_PAGE', 'admin');
require PUN_ROOT.'header.php';

generate_admin_menu('index');

?>
	<div class="block">
		<h2><span><?php echo $lang_admin_index['Forum admin head'] ?></span></h2>
		<div id="adintro" class="box">
			<div class="inbox">
				<p><?php echo $lang_admin_index['Welcome to admin'] ?></p>
				<ul>
					<li><span><?php echo $lang_admin_index['Welcome 1'] ?></span></li>
					<li><span><?php echo $lang_admin_index['Welcome 2'] ?></span></li>
					<li><span><?php echo $lang_admin_index['Welcome 3'] ?></span></li>
					<li><span><?php echo $lang_admin_index['Welcome 4'] ?></span></li>
					<li><span><?php echo $lang_admin_index['Welcome 5'] ?></span></li>
					<li><span><?php echo $lang_admin_index['Welcome 6'] ?></span></li>
					<li><span><?php echo $lang_admin_index['Welcome 7'] ?></span></li>
					<li><span><?php echo $lang_admin_index['Welcome 8'] ?></span></li>
					<li><span><?php echo $lang_admin_index['Welcome 9'] ?></span></li>
				</ul>
			</div>
		</div>

		<h2 class="block2"><span><?php echo $lang_admin_index['About head'] ?></span></h2>
		<div id="adstats" class="box">
			<div class="inbox">
				<dl>
					<dt><?php echo $lang_admin_index['ModernBB version label'] ?></dt>
					<dd>
						<?php printf($lang_admin_index['ModernBB version data']."\n", $pun_config['o_cur_version'], '<a href="admin_index.php?action=check_upgrade">'.$lang_admin_index['Check for upgrade'].'</a>') ?>
					</dd>
					<dt><?php echo $lang_admin_index['Server statistics label'] ?></dt>
					<dd>
						<a href="admin_statistics.php"><?php echo $lang_admin_index['View server statistics'] ?></a>
					</dd>
					<dt><?php echo $lang_admin_index['Support label'] ?></dt>
					<dd>
						<a href="http://modernbb.be/forum/index.php"><?php echo $lang_admin_index['Forum label'] ?></a>
					</dd>
				</dl>
			</div>
		</div>
	</div>
	<div class="clearer"></div>
</div>
<?php

require PUN_ROOT.'footer.php';
