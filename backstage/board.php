<?php

/*
 * Copyright (C) 2013-2015 Luna
 * Based on code by FluxBB copyright (C) 2008-2012 FluxBB
 * Based on code by Rickard Andersson copyright (C) 2002-2008 PunBB
 * Licensed under GPLv3 (http://getluna.org/license.php)
 */

define('LUNA_ROOT', '../');
require LUNA_ROOT.'include/common.php';

if (!$is_admin)
	header("Location: login.php");
// Add a "default" forum
if (isset($_POST['add_forum'])) {
	confirm_referrer('backstage/board.php');
	
	$forum_name = luna_trim($_POST['new_forum']); 
	$add_to_cat = intval($_POST['add_to_cat']);
	if ($add_to_cat < 1)
		message_backstage(__('Bad request. The link you followed is incorrect, outdated or you are simply not allowed to hang around here.', 'luna'), false, '404 Not Found');

	$db->query('INSERT INTO '.$db->prefix.'forums (forum_name, cat_id) VALUES(\''.$db->escape($forum_name).'\', '.$add_to_cat.')') or error('Unable to create forum', __FILE__, __LINE__, $db->error());
	$new_fid = $db->insert_id();

	// Regenerate the forum cache
	if (!defined('LUNA_CACHE_FUNCTIONS_LOADED'))
		require LUNA_ROOT.'include/cache.php';
	
	generate_forum_cache();

	redirect('backstage/board.php?edit_forum='.$new_fid);
}

// Delete a forum
elseif (isset($_GET['del_forum'])) {
	confirm_referrer('backstage/board.php');
	
	$forum_id = intval($_GET['del_forum']);
	if ($forum_id < 1)
		message_backstage(__('Bad request. The link you followed is incorrect, outdated or you are simply not allowed to hang around here.', 'luna'), false, '404 Not Found');

	if (isset($_POST['del_forum_comply'])) { // Delete a forum with all comments
		@set_time_limit(0);

		// Prune all comments and threads
		prune($forum_id, 1, -1);

		// Locate any "orphaned redirect threads" and delete them
		$result = $db->query('SELECT t1.id FROM '.$db->prefix.'threads AS t1 LEFT JOIN '.$db->prefix.'threads AS t2 ON t1.moved_to=t2.id WHERE t2.id IS NULL AND t1.moved_to IS NOT NULL') or error('Unable to fetch redirect threads', __FILE__, __LINE__, $db->error());
		$num_orphans = $db->num_rows($result);

		if ($num_orphans) {
			for ($i = 0; $i < $num_orphans; ++$i)
				$orphans[] = $db->result($result, $i);

			$db->query('DELETE FROM '.$db->prefix.'threads WHERE id IN('.implode(',', $orphans).')') or error('Unable to delete redirect threads', __FILE__, __LINE__, $db->error());
		}

		// Delete the forum and any forum specific group permissions
		$db->query('DELETE FROM '.$db->prefix.'forums WHERE id='.$forum_id) or error('Unable to delete forum', __FILE__, __LINE__, $db->error());
		$db->query('DELETE FROM '.$db->prefix.'forum_perms WHERE forum_id='.$forum_id) or error('Unable to delete group forum permissions', __FILE__, __LINE__, $db->error());

		// Delete any subscriptions for this forum
		$db->query('DELETE FROM '.$db->prefix.'forum_subscriptions WHERE forum_id='.$forum_id) or error('Unable to delete subscriptions', __FILE__, __LINE__, $db->error());

		// Regenerate the forum cache
		if (!defined('LUNA_CACHE_FUNCTIONS_LOADED'))
			require LUNA_ROOT.'include/cache.php';
		
		generate_forum_cache();

		redirect('backstage/board.php?saved=true');
	} else { // If the user hasn't confirmed the delete
		$result = $db->query('SELECT forum_name FROM '.$db->prefix.'forums WHERE id='.$forum_id) or error('Unable to fetch forum info', __FILE__, __LINE__, $db->error());
		$forum_name = luna_htmlspecialchars($db->result($result));

		$page_title = array(luna_htmlspecialchars($luna_config['o_board_title']), __('Admin', 'luna'), __('Forums', 'luna'));
		define('LUNA_ACTIVE_PAGE', 'admin');
		require 'header.php';
	load_admin_nav('content', 'board');

?>
<form method="post" action="board.php?del_forum=<?php echo $forum_id ?>">
	<fieldset>
		<div class="panel panel-danger">
			<div class="panel-heading">
				<h3 class="panel-title"><?php _e('Confirm delete forum', 'luna') ?></h3>
			</div>
			<div class="panel-body">
				<p><?php printf(__('Are you sure that you want to delete the forum <strong>%s</strong>?', 'luna'), $forum_name) ?> <?php _e('Deleting a forum will delete all comments (if any) in that forum!', 'luna') ?></p>
			</div>
			<div class="panel-footer">
				<button class="btn btn-danger" type="submit" name="del_forum_comply"><span class="fa fa-fw fa-trash"></span> <?php _e('Remove', 'luna') ?></button>
			</div>
		</div>
	</fieldset>
</form>
<?php

		require 'footer.php';
	}
}

// Update forum positions
elseif (isset($_POST['update_positions'])) {
	confirm_referrer('backstage/board.php');
	
	foreach ($_POST['position'] as $forum_id => $disp_position) {
		$disp_position = trim($disp_position);
		if ($disp_position == '' || preg_match('%[^0-9]%', $disp_position))
			message_backstage(__('Position must be a positive integer value.', 'luna'));

		$db->query('UPDATE '.$db->prefix.'forums SET disp_position='.$disp_position.' WHERE id='.intval($forum_id)) or error('Unable to update forum', __FILE__, __LINE__, $db->error());
	}
	
	// Regenerate the forum cache
	if (!defined('LUNA_CACHE_FUNCTIONS_LOADED'))
		require LUNA_ROOT.'include/cache.php';
	
	generate_forum_cache();

	redirect('backstage/board.php?saved=true');
} elseif (isset($_GET['edit_forum'])) {
	$forum_id = intval($_GET['edit_forum']);
	if ($forum_id < 1)
		message_backstage(__('Bad request. The link you followed is incorrect, outdated or you are simply not allowed to hang around here.', 'luna'), false, '404 Not Found');

	// Update group permissions for $forum_id
	if (isset($_POST['save'])) {
		confirm_referrer('backstage/board.php');
	
		// Start with the forum details
		$forum_name = luna_trim($_POST['forum_name']);
		$forum_desc = luna_linebreaks(luna_trim($_POST['forum_desc']));
		$parent_id = intval($_POST['parent_id']);
		$cat_id = intval($_POST['cat_id']);
		$sort_by = intval($_POST['sort_by']);
		$icon = luna_trim($_POST['icon']);
		$color = luna_trim($_POST['color']);
		$solved = isset($_POST['solved']) ? '1' : '0';

		if ($forum_name == '')
			message_backstage(__('You must enter a name', 'luna'));

		if ($cat_id < 1)
			message_backstage(__('Bad request. The link you followed is incorrect, outdated or you are simply not allowed to hang around here.', 'luna'), false, '404 Not Found');

		$forum_desc = ($forum_desc != '') ? '\''.$db->escape($forum_desc).'\'' : 'NULL';

		$db->query('UPDATE '.$db->prefix.'forums SET forum_name=\''.$db->escape($forum_name).'\', forum_desc='.$forum_desc.', parent_id='.$parent_id.', sort_by='.$sort_by.', cat_id='.$cat_id.', icon=\''.$db->escape($icon).'\', color=\''.$color.'\', solved='.$solved.' WHERE id='.$forum_id) or error('Unable to update forum', __FILE__, __LINE__, $db->error());
		
		// Now let's deal with the permissions
		if (isset($_POST['read_forum_old'])) {
			$result = $db->query('SELECT g_id, g_read_board, g_comment, g_create_threads FROM '.$db->prefix.'groups WHERE g_id!='.LUNA_ADMIN) or error('Unable to fetch user group list', __FILE__, __LINE__, $db->error());
			while ($cur_group = $db->fetch_assoc($result)) {
				$read_forum_new = ($cur_group['g_read_board'] == '1') ? isset($_POST['read_forum_new'][$cur_group['g_id']]) ? '1' : '0' : intval($_POST['read_forum_old'][$cur_group['g_id']]);
				$comment_new = isset($_POST['comment_new'][$cur_group['g_id']]) ? '1' : '0';
				$create_threads_new = isset($_POST['create_threads_new'][$cur_group['g_id']]) ? '1' : '0';

				// Check if the new settings differ from the old
				if ($read_forum_new != $_POST['read_forum_old'][$cur_group['g_id']] || $comment_new != $_POST['comment_old'][$cur_group['g_id']] || $create_threads_new != $_POST['create_threads_old'][$cur_group['g_id']]) {
					// If the new settings are identical to the default settings for this group, delete its row in forum_perms
					if ($read_forum_new == '1' && $comment_new == $cur_group['g_comment'] && $create_threads_new == $cur_group['g_create_threads'])
						$db->query('DELETE FROM '.$db->prefix.'forum_perms WHERE group_id='.$cur_group['g_id'].' AND forum_id='.$forum_id) or error('Unable to delete group forum permissions', __FILE__, __LINE__, $db->error());
					else {
						// Run an UPDATE and see if it affected a row, if not, INSERT
						$db->query('UPDATE '.$db->prefix.'forum_perms SET read_forum='.$read_forum_new.', comment='.$comment_new.', create_threads='.$create_threads_new.' WHERE group_id='.$cur_group['g_id'].' AND forum_id='.$forum_id) or error('Unable to insert group forum permissions', __FILE__, __LINE__, $db->error());
						if (!$db->affected_rows())
							$db->query('INSERT INTO '.$db->prefix.'forum_perms (group_id, forum_id, read_forum, comment, create_threads) VALUES('.$cur_group['g_id'].', '.$forum_id.', '.$read_forum_new.', '.$comment_new.', '.$create_threads_new.')') or error('Unable to insert group forum permissions', __FILE__, __LINE__, $db->error());
					}
				}
			}
		}

		// Regenerate the forum cache
		if (!defined('LUNA_CACHE_FUNCTIONS_LOADED'))
			require LUNA_ROOT.'include/cache.php';
		
		generate_forum_cache();

		redirect('backstage/board.php?saved=true');
	} elseif (isset($_POST['revert_perms'])) {
		confirm_referrer('backstage/board.php');
	
		$db->query('DELETE FROM '.$db->prefix.'forum_perms WHERE forum_id='.$forum_id) or error('Unable to delete group forum permissions', __FILE__, __LINE__, $db->error());

		// Regenerate the forum cache
		if (!defined('LUNA_CACHE_FUNCTIONS_LOADED'))
			require LUNA_ROOT.'include/cache.php';
		
		generate_forum_cache();

		redirect('backstage/board.php?edit_forum='.$forum_id);
	}

	// Fetch forum info
	$result = $db->query('SELECT id, forum_name, forum_desc, parent_id, num_threads, sort_by, cat_id, icon, color, solved FROM '.$db->prefix.'forums WHERE id='.$forum_id) or error('Unable to fetch forum info', __FILE__, __LINE__, $db->error());

	if (!$db->num_rows($result))
		message_backstage(__('Bad request. The link you followed is incorrect, outdated or you are simply not allowed to hang around here.', 'luna'), false, '404 Not Found');

	$cur_forum = $db->fetch_assoc($result);

	$parent_forums = Array();
	$result = $db->query('SELECT DISTINCT parent_id FROM '.$db->prefix.'forums WHERE parent_id != 0');
	while ($r = $db->fetch_row($result))
		$parent_forums[] = $r[0];
	
	$cur_index = 7;
	
	$page_title = array(luna_htmlspecialchars($luna_config['o_board_title']), __('Admin', 'luna'), __('Forums', 'luna'));
	define('LUNA_ACTIVE_PAGE', 'admin');
	require 'header.php';
	load_admin_nav('content', 'board');

?>
<form id="edit_forum" class="form-horizontal" method="post" action="board.php?edit_forum=<?php echo $forum_id ?>">
	<div class="panel panel-default">
		<div class="panel-heading">
			<h3 class="panel-title"><?php _e('Forum details', 'luna') ?><span class="pull-right"><button class="btn btn-primary" type="submit" name="save"><span class="fa fa-fw fa-check"></span> <?php _e('Save', 'luna') ?></button></span></h3>
		</div>
		<div class="panel-body">
			<fieldset>
				<div class="form-group">
					<label class="col-sm-3 control-label"><?php _e('Forum name', 'luna') ?></label>
					<div class="col-sm-9">
						<input type="text" class="form-control" name="forum_name" maxlength="80" value="<?php echo luna_htmlspecialchars($cur_forum['forum_name']) ?>" tabindex="1" />
					</div>
				</div>
				<div class="form-group">
					<label class="col-sm-3 control-label"><?php _e('Description', 'luna') ?></label>
					<div class="col-sm-9">
						<textarea class="form-control" name="forum_desc" rows="3" tabindex="2"><?php echo luna_htmlspecialchars($cur_forum['forum_desc']) ?></textarea>
					</div>
				</div>
				<div class="form-group">
					<label class="col-sm-3 control-label"><?php _e('Parent section', 'luna') ?></label>
					<div class="col-sm-9">
						<select name="parent_id" class="form-control">
							<option value="0"><?php _e('No parent forum selected', 'luna') ?></option>
<?php

	if (!in_array($cur_forum['id'],$parent_forums)) {
		$result = $db->query('SELECT c.id AS cid, c.cat_name, f.id, f.forum_name, f.parent_id FROM '.$db->prefix.'categories AS c INNER JOIN '.$db->prefix.'forums AS f ON c.id=f.cat_id ORDER BY c.disp_position, c.id, f.disp_position', true) or error('Unable to fetch category/forum list', __FILE__, __LINE__, $db->error());

		$cur_category = 0;
		while ($forum_list = $db->fetch_assoc($result)) {
			if ($forum_list['cid'] != $cur_category) { // A new category since last iteration?
				if ($cur_category)
					echo "\t\t\t\t\t\t".'</optgroup>'."\n";

				echo "\t\t\t\t\t\t".'<optgroup label="'.luna_htmlspecialchars($forum_list['cat_name']).'">'."\n";
				$cur_category = $forum_list['cid'];
			}

			$selected = ($forum_list['id'] == $cur_forum['parent_id']) ? ' selected' : '';

			if(!$forum_list['parent_id'] && $forum_list['id'] != $cur_forum['id'])
				echo "\t\t\t\t\t\t\t".'<option value="'.$forum_list['id'].'"'.$selected.'>'.luna_htmlspecialchars($forum_list['forum_name']).'</option>'."\n";
		}
	}

?>
							</optgroup>
						</select>
					</div>
				</div>
				<div class="form-group">
					<label class="col-sm-3 control-label"><?php _e('Category', 'luna') ?></label>
					<div class="col-sm-9">
						<select class="form-control" name="cat_id" tabindex="3">
<?php

	$result = $db->query('SELECT id, cat_name FROM '.$db->prefix.'categories ORDER BY disp_position') or error('Unable to fetch category list', __FILE__, __LINE__, $db->error());
	while ($cur_cat = $db->fetch_assoc($result)) {
		$selected = ($cur_cat['id'] == $cur_forum['cat_id']) ? ' selected' : '';
		echo "\t\t\t\t\t\t\t\t\t\t\t".'<option value="'.$cur_cat['id'].'"'.$selected.'>'.luna_htmlspecialchars($cur_cat['cat_name']).'</option>'."\n";
	}

?>
						</select>
					</div>
				</div>
				<div class="form-group">
					<label class="col-sm-3 control-label"><?php _e('Sort threads by', 'luna') ?></label>
					<div class="col-sm-9">
						<select class="form-control" name="sort_by" tabindex="4">
							<option value="0"<?php if ($cur_forum['sort_by'] == '0') echo ' selected' ?>><?php _e('Last comment', 'luna') ?></option>
							<option value="1"<?php if ($cur_forum['sort_by'] == '1') echo ' selected' ?>><?php _e('Thread start', 'luna') ?></option>
							<option value="2"<?php if ($cur_forum['sort_by'] == '2') echo ' selected' ?>><?php _e('Subject', 'luna') ?></option>
						</select>
					</div>
				</div>
				<div class="form-group">
					<label class="col-sm-3 control-label"><?php _e('Icon', 'luna') ?><span class="help-block"><?php printf(__('The Font Awesome icon you want to show next to the title, for a full overview, see the %s', 'luna'), '<a href="http://fortawesome.github.io/Font-Awesome/icons/">'.__('Font Awesome icon guide', 'luna').'</a>') ?></span></label>
					<div class="col-sm-9">
						<div class="input-group">
							<span class="input-group-addon">fa fa-fw fa-</span>
							<input type="text" class="form-control" name="icon" maxlength="50" value="<?php echo luna_htmlspecialchars($cur_forum['icon']) ?>" tabindex="1" />
						</div>
					</div>
				</div>
				<div class="form-group">
					<label class="col-sm-3 control-label"><?php _e('Forum color', 'luna') ?></label>
					<div class="col-sm-9">
						<input id="color" name="color" value="<?php echo $cur_forum['color'] ?>" />
					</div>
				</div>
				<div class="form-group">
					<label class="col-sm-3 control-label"><?php _e('Solved', 'luna') ?></label>
					<div class="col-sm-9">
						<div class="checkbox">
							<label>
								<input type="checkbox" name="solved" value="1" <?php if ($cur_forum['solved'] == '1') echo ' checked' ?> />
								<?php _e('Threads in this forum can be marked as solved.', 'luna') ?>
							</label>
						</div>
					</div>
				</div>
			</fieldset>
		</div>
	</div>
	<div class="panel panel-default">
		<div class="panel-heading">
			<h3 class="panel-title"><?php _e('Group permissions', 'luna') ?><span class="pull-right"><button class="btn btn-primary" type="submit" name="save"><span class="fa fa-fw fa-check"></span> <?php _e('Save', 'luna') ?></button></span></h3>
		</div>
		<fieldset>
			<div class="panel-body">
				<p><?php printf(__('Here you can set the forum specific permissions for the different user groups. Administrators always have full permissions. Permission settings that differ from the default permissions for the user group are marked red. Some permissions are disabled under some conditions.', 'luna'), '<a href="groups.php">'.__('User groups', 'luna').'</a>') ?></p>
				<div><input class="btn btn-warning pull-right" type="submit" name="revert_perms" value="<?php _e('Revert to default', 'luna') ?>" tabindex="<?php echo $cur_index++ ?>" /></div>
			</div>
			<table class="table">
				<thead>
					<tr>
						<th>&#160;</th>
						<th><?php _e('Read forum', 'luna') ?></th>
						<th><?php _e('Comment', 'luna') ?></th>
						<th><?php _e('Create threads', 'luna') ?></th>
					</tr>
				</thead>
				<tbody>
<?php

	$result = $db->query('SELECT g.g_id, g.g_title, g.g_read_board, g.g_comment, g.g_create_threads, fp.read_forum, fp.comment, fp.create_threads FROM '.$db->prefix.'groups AS g LEFT JOIN '.$db->prefix.'forum_perms AS fp ON (g.g_id=fp.group_id AND fp.forum_id='.$forum_id.') WHERE g.g_id!='.LUNA_ADMIN.' ORDER BY g.g_id') or error('Unable to fetch group forum permission list', __FILE__, __LINE__, $db->error());

	while ($cur_perm = $db->fetch_assoc($result)) {
		$read_forum = ($cur_perm['read_forum'] != '0') ? true : false;
		$comment = (($cur_perm['g_comment'] == '0' && $cur_perm['comment'] == '1') || ($cur_perm['g_comment'] == '1' && $cur_perm['comment'] != '0')) ? true : false;
		$create_threads = (($cur_perm['g_create_threads'] == '0' && $cur_perm['create_threads'] == '1') || ($cur_perm['g_create_threads'] == '1' && $cur_perm['create_threads'] != '0')) ? true : false;

		// Determine if the current settings differ from the default or not
		$read_forum_def = ($cur_perm['read_forum'] == '0') ? false : true;
		$comment_def = (($comment && $cur_perm['g_comment'] == '0') || (!$comment && ($cur_perm['g_comment'] == '' || $cur_perm['g_comment'] == '1'))) ? false : true;
		$create_threads_def = (($create_threads && $cur_perm['g_create_threads'] == '0') || (!$create_threads && ($cur_perm['g_create_threads'] == '' || $cur_perm['g_create_threads'] == '1'))) ? false : true;

?>
					<tr>
						<th class="atcl"><?php echo luna_htmlspecialchars($cur_perm['g_title']) ?></th>
						<td<?php if (!$read_forum_def) echo ' class="danger"'; ?>>
							<input type="hidden" name="read_forum_old[<?php echo $cur_perm['g_id'] ?>]" value="<?php echo ($read_forum) ? '1' : '0'; ?>" />
							<input type="checkbox" name="read_forum_new[<?php echo $cur_perm['g_id'] ?>]" value="1"<?php echo ($read_forum) ? ' checked' : ''; ?><?php echo ($cur_perm['g_read_board'] == '0') ? ' disabled="disabled"' : ''; ?> tabindex="<?php echo $cur_index++ ?>" />
						</td>
						<td<?php if (!$comment_def) echo ' class="danger"'; ?>>
							<input type="hidden" name="comment_old[<?php echo $cur_perm['g_id'] ?>]" value="<?php echo ($comment) ? '1' : '0'; ?>" />
							<input type="checkbox" name="comment_new[<?php echo $cur_perm['g_id'] ?>]" value="1"<?php echo ($comment) ? ' checked' : ''; ?> tabindex="<?php echo $cur_index++ ?>" />
						</td>
						<td<?php if (!$create_threads_def) echo ' class="danger"'; ?>>
							<input type="hidden" name="create_threads_old[<?php echo $cur_perm['g_id'] ?>]" value="<?php echo ($create_threads) ? '1' : '0'; ?>" />
							<input type="checkbox" name="create_threads_new[<?php echo $cur_perm['g_id'] ?>]" value="1"<?php echo ($create_threads) ? ' checked' : ''; ?> tabindex="<?php echo $cur_index++ ?>" />
						</td>
					</tr>
<?php

}

?>
				</tbody>
			</table>
		</fieldset>
	</div>
</form>

<?php

	require 'footer.php';
}

// Add a new category
elseif (isset($_POST['add_cat'])) {
	confirm_referrer('backstage/board.php');
	
	$new_cat_name = luna_trim($_POST['new_cat_name']);
	if ($new_cat_name == '')
		message_backstage(__('You must enter a name', 'luna'));

	$db->query('INSERT INTO '.$db->prefix.'categories (cat_name) VALUES(\''.$db->escape($new_cat_name).'\')') or error('Unable to create category', __FILE__, __LINE__, $db->error());

	redirect('backstage/board.php?saved=true');
}

// Delete a category
elseif (isset($_POST['del_cat']) || isset($_POST['del_cat_comply'])) {
	confirm_referrer('backstage/board.php');
	
	$cat_to_delete = intval($_POST['cat_to_delete']);
	if ($cat_to_delete < 1)
		message_backstage(__('Bad request. The link you followed is incorrect, outdated or you are simply not allowed to hang around here.', 'luna'), false, '404 Not Found');

	if (isset($_POST['del_cat_comply'])) { // Delete a category with all forums and comments
		@set_time_limit(0);

		$result = $db->query('SELECT id FROM '.$db->prefix.'forums WHERE cat_id='.$cat_to_delete) or error('Unable to fetch forum list', __FILE__, __LINE__, $db->error());
		$num_forums = $db->num_rows($result);

		for ($i = 0; $i < $num_forums; ++$i) {
			$cur_forum = $db->result($result, $i);

			// Prune all comments and threads
			prune($cur_forum, 1, -1);

			// Delete the forum
			$db->query('DELETE FROM '.$db->prefix.'forums WHERE id='.$cur_forum) or error('Unable to delete forum', __FILE__, __LINE__, $db->error());
		}

		// Locate any "orphaned redirect threads" and delete them
		$result = $db->query('SELECT t1.id FROM '.$db->prefix.'threads AS t1 LEFT JOIN '.$db->prefix.'threads AS t2 ON t1.moved_to=t2.id WHERE t2.id IS NULL AND t1.moved_to IS NOT NULL') or error('Unable to fetch redirect threads', __FILE__, __LINE__, $db->error());
		$num_orphans = $db->num_rows($result);

		if ($num_orphans) {
			for ($i = 0; $i < $num_orphans; ++$i)
				$orphans[] = $db->result($result, $i);

			$db->query('DELETE FROM '.$db->prefix.'threads WHERE id IN('.implode(',', $orphans).')') or error('Unable to delete redirect threads', __FILE__, __LINE__, $db->error());
		}

		// Delete the category
		$db->query('DELETE FROM '.$db->prefix.'categories WHERE id='.$cat_to_delete) or error('Unable to delete category', __FILE__, __LINE__, $db->error());

		// Regenerate the quick jump cache
		if (!defined('LUNA_CACHE_FUNCTIONS_LOADED'))
			require LUNA_ROOT.'include/cache.php';

		redirect('backstage/board.php?saved=true');
	} else { // If the user hasn't confirmed the delete
		$result = $db->query('SELECT cat_name FROM '.$db->prefix.'categories WHERE id='.$cat_to_delete) or error('Unable to fetch category info', __FILE__, __LINE__, $db->error());
		$cat_name = $db->result($result);

		$page_title = array(luna_htmlspecialchars($luna_config['o_board_title']), __('Admin', 'luna'), __('Categories', 'luna'));
		define('LUNA_ACTIVE_PAGE', 'admin');
		require 'header.php';
	load_admin_nav('content', 'board');

?>
<form method="post" action="board.php">
	<input type="hidden" name="cat_to_delete" value="<?php echo $cat_to_delete ?>" />
	<fieldset>
		<div class="panel panel-danger">
			<div class="panel-heading">
				<h3 class="panel-title"><?php _e('Confirm delete category', 'luna') ?></h3>
			</div>
			<div class="panel-body">
				<p><?php printf(__('Are you sure that you want to delete the category <strong>%s</strong>?', 'luna'), $cat_name) ?> <?php _e('Deleting a category will delete all forums and comments (if any) in this category!', 'luna') ?></p>
			</div>
			<div class="panel-footer">
				<button class="btn btn-danger" type="submit" name="del_cat_comply"><span class="fa fa-fw fa-trash"></span> <?php _e('Remove', 'luna') ?></button>
			</div>
		</div>
	</fieldset>
</form>
<?php

		require 'footer.php';
	}
} else {

	// Generate an array with all categories
	$result = $db->query('SELECT id, cat_name, disp_position FROM '.$db->prefix.'categories ORDER BY disp_position') or error('Unable to fetch category list', __FILE__, __LINE__, $db->error());
	$num_cats = $db->num_rows($result);
	
	for ($i = 0; $i < $num_cats; ++$i)
		$cat_list[] = $db->fetch_assoc($result);
	
	if (isset($_POST['update'])) { // Change position and name of the categories
		confirm_referrer('backstage/board.php');
		
		$categories = $_POST['cat'];
		if (empty($categories))
			message_backstage(__('Bad request. The link you followed is incorrect, outdated or you are simply not allowed to hang around here.', 'luna'), false, '404 Not Found');
	
		foreach ($categories as $cat_id => $cur_cat) {
			$cur_cat['name'] = luna_trim($cur_cat['name']);
			$cur_cat['order'] = luna_trim($cur_cat['order']);
	
			if ($cur_cat['name'] == '')
				message_backstage(__('You must enter a name', 'luna'));
	
			if ($cur_cat['order'] == '' || preg_match('%[^0-9]%', $cur_cat['order']))
				message_backstage(__('Position must be a positive integer value.', 'luna'));
	
			$db->query('UPDATE '.$db->prefix.'categories SET cat_name=\''.$db->escape($cur_cat['name']).'\', disp_position='.$cur_cat['order'].' WHERE id='.intval($cat_id)) or error('Unable to update category', __FILE__, __LINE__, $db->error());
		}
	
		redirect('backstage/board.php?saved=true');
	}
	
	$page_title = array(luna_htmlspecialchars($luna_config['o_board_title']), __('Admin', 'luna'), __('Board', 'luna'));
	define('LUNA_ACTIVE_PAGE', 'admin');
	require 'header.php';
		load_admin_nav('content', 'board');
	
	if (isset($_GET['saved']))
		echo '<div class="alert alert-success">'.__('Your settings have been saved.', 'luna').'</div>'
?>
<div class="row">
	<div class="col-lg-4">
		<form method="post" action="board.php?action=add_forum">
<?php
	$result = $db->query('SELECT id, cat_name FROM '.$db->prefix.'categories ORDER BY disp_position') or error('Unable to fetch category list', __FILE__, __LINE__, $db->error());
	if ($db->num_rows($result) > 0) {
?>
			<div class="panel panel-default">
				<div class="panel-heading">
					<h3 class="panel-title"><?php _e('Add forum', 'luna') ?><span class="pull-right"><button class="btn btn-primary" type="submit" name="add_forum" tabindex="2"><span class="fa fa-fw fa-plus"></span> <?php _e('Add', 'luna') ?></button></span></h3>
				</div>
				<fieldset>
					<table class="table">
						<tbody>
							<tr>
								<td>
									<select class="form-control" name="add_to_cat" tabindex="1">
<?php
		while ($cur_cat = $db->fetch_assoc($result))
			echo "\t\t\t\t\t\t\t\t\t\t\t".'<option value="'.$cur_cat['id'].'">'.luna_htmlspecialchars($cur_cat['cat_name']).'</option>'."\n";
?>
									</select>
								</td>
							</tr>
							<tr>
								<td><input type="text" class="form-control" name="new_forum" maxlength="80" placeholder="<?php _e('Name', 'luna') ?>" required="required" /></td>
							</tr>
						</tbody>
					</table>
				</fieldset>
			</div>
		</form>
<?php
	}
?>
		<form method="post" action="board.php">
			<div class="panel panel-default">
				<div class="panel-heading">
					<h3 class="panel-title"><?php _e('Add categories', 'luna') ?><span class="pull-right"><button class="btn btn-primary" type="submit" name="add_cat" tabindex="2"><span class="fa fa-fw fa-plus"></span> <?php _e('Add', 'luna') ?></button></span></h3>
				</div>
				<fieldset>
					<table class="table">
						<tbody>
							<tr>
								<td><input type="text" class="form-control" name="new_cat_name" maxlength="80" placeholder="<?php _e('Name', 'luna') ?>" tabindex="1" /></td>
							</tr>
						</tbody>
					</table>
				</fieldset>
			</div>
		</form>
	<?php if ($num_cats): ?>
		<form method="post" action="board.php">
			<div class="panel panel-default">
				<div class="panel-heading">
					<h3 class="panel-title"><?php _e('Delete categories', 'luna') ?><span class="pull-right"><button class="btn btn-danger" type="submit" name="del_cat" tabindex="4"><span class="fa fa-fw fa-trash"></span> <?php _e('Remove', 'luna') ?></button></span></h3>
				</div>
				<fieldset>
					<table class="table">
						<tbody>
							<tr>
								<td>
									<select class="form-control" name="cat_to_delete" tabindex="3">
<?php
									foreach ($cat_list as $cur_cat)
										echo "\t\t\t\t\t\t\t\t\t\t\t".'<option value="'.$cur_cat['id'].'">'.luna_htmlspecialchars($cur_cat['cat_name']).'</option>'."\n";
?>
									</select>
								</td>
							</tr>
						</tbody>
					</table>
				</fieldset>
			</div>
		</form>
	</div>
	<?php endif; ?>
<?php

// Display all the categories and forums
$result = $db->query('SELECT c.id AS cid, c.cat_name, f.id AS fid, f.forum_name, f.disp_position FROM '.$db->prefix.'categories AS c INNER JOIN '.$db->prefix.'forums AS f ON c.id=f.cat_id ORDER BY c.disp_position, c.id, f.disp_position') or error('Unable to fetch category/forum list', __FILE__, __LINE__, $db->error());

$cur_index = 4;

if ($db->num_rows($result) > 0) {

?>
	<div class="col-lg-8">
		<form id="edforum" method="post" action="board.php?action=edit">
			<div class="panel panel-default">
				<div class="panel-heading">
					<h3 class="panel-title"><?php _e('Edit forum', 'luna') ?><span class="pull-right"><button class="btn btn-primary" type="submit" name="update_positions"><span class="fa fa-fw fa-check"></span> <?php _e('Save', 'luna') ?></button></span></h3>
				</div>
				<fieldset>
<?php

$cur_index = 4;

$cur_category = 0;
while ($cur_forum = $db->fetch_assoc($result)) {
	if ($cur_forum['cid'] != $cur_category) { // A new category since last iteration?
		if ($cur_category != 0)
			echo "\t\t\t\t\t\t\t".'</tbody>'."\n\t\t\t\t\t\t\t".'</table>'."\n";

?>
					<table class="table table-hover table-forums">
						<tbody>
							<tr>
								<th colspan="3" class="active">
									<h4><?php echo luna_htmlspecialchars($cur_forum['cat_name']) ?></h4>
								</th>
							</tr>
<?php

		$cur_category = $cur_forum['cid'];
	}

?>
							<tr>
								<td class="col-xs-6 col-sm-4"><div class="btn-group"><a class="btn btn-primary" href="board.php?edit_forum=<?php echo $cur_forum['fid'] ?>" tabindex="<?php echo $cur_index++ ?>"><span class="fa fa-fw fa-pencil-square-o"></span> <?php _e('Edit', 'luna') ?></a><a class="btn btn-danger" href="board.php?del_forum=<?php echo $cur_forum['fid'] ?>" tabindex="<?php echo $cur_index++ ?>"><span class="fa fa-fw fa-trash"></span> <?php _e('Remove', 'luna') ?></a></div></td>
								<td class="col-xs-4 col-sm-6"><strong><?php echo luna_htmlspecialchars($cur_forum['forum_name']) ?></strong></td>
								<td class="col-xs-2 col-sm-2"><input type="number" class="form-control" name="position[<?php echo $cur_forum['fid'] ?>]" maxlength="3" value="<?php echo $cur_forum['disp_position'] ?>" tabindex="<?php echo $cur_index++ ?>" /></td>
							</tr>
<?php

}

?>
						</tbody>
					</table>
				</fieldset>
			</div>
		</form>
<?php if ($num_cats): ?>
		<form method="post" action="board.php">
			<div class="panel panel-default">
				<div class="panel-heading">
					<h3 class="panel-title"><?php _e('Edit categories', 'luna') ?><span class="pull-right"><button class="btn btn-primary" type="submit" name="update"><span class="fa fa-fw fa-check"></span> <?php _e('Save', 'luna') ?></button></span></h3>
				</div>
				<fieldset>
					<table class="table">
						<thead>
							<tr>
								<th class="col-xs-8"><?php _e('Name', 'luna') ?></th>
								<th class="col-xs-4"><?php _e('Position', 'luna') ?></th>
							</tr>
						</thead>
						<tbody>
<?php

foreach ($cat_list as $cur_cat) {

?>
							<tr>
								<td><input type="text" class="form-control" name="cat[<?php echo $cur_cat['id'] ?>][name]" value="<?php echo luna_htmlspecialchars($cur_cat['cat_name']) ?>" maxlength="80" /></td>
								<td><input type="number" class="form-control" name="cat[<?php echo $cur_cat['id'] ?>][order]" value="<?php echo $cur_cat['disp_position'] ?>" maxlength="3" /></td>
							</tr>
<?php

}

?>
						</tbody>
					</table>
				</fieldset>
			</div>
		</form>
	</div>
</div>
<?php endif; 
	}

require 'footer.php';
}
