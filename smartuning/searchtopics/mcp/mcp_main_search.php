<?php
/**
* Search Topics MCP extension for the phpBB Forum Software package
* 
* This extension will add a new panel to the moderation control panel
* from where you can search for topics and bulk move and delete them.
* 
* This code has been derived from the original MCP MAIN extention
* by Smartuning (www.ecuconnections.com)
* 
*/

/**
*
* This file is part of the phpBB Forum Software package.
*
* @copyright (c) phpBB Limited <https://www.phpbb.com>
* @license GNU General Public License, version 2 (GPL-2.0)
*
* For full copyright and license information, please see
* the docs/CREDITS.txt file.
*
*/

/**
* @ignore
*/
if (!defined('IN_PHPBB'))
{
	exit;
}

/**
* mcp_main
* Handling mcp actions
*/
class mcp_main_search
{
	var $p_master;
	var $u_action;

	function __construct(&$p_master)
	{
		$this->p_master = &$p_master;
	}

	function main($id, $mode)
	{
		global $auth, $user, $action;
		global $phpbb_root_path, $phpEx, $request;
		global $phpbb_dispatcher;

		$quickmod = ($mode == 'quickmod') ? true : false;

		switch ($action)
		{
			case 'move':
				$user->add_lang('viewtopic');

				$topic_ids = (!$quickmod) ? $request->variable('topic_id_list', array(0)) : array($request->variable('t', 0));

				if (!count($topic_ids))
				{
					trigger_error('NO_MATCHES_FOUND');
				}

				mcp_move_topic($topic_ids);
			break;

			case 'delete_topic':
				$user->add_lang('viewtopic');

				// f parameter is not reliable for permission usage, however we just use it to decide
				// which permission we will check later on. So if it is manipulated, we will still catch it later on.
				$forum_id = $request->variable('f', 0);
				$topic_ids = (!$quickmod) ? $request->variable('topic_id_list', array(0)) : array($request->variable('t', 0));
				$soft_delete = (($request->is_set_post('confirm') && !$request->is_set_post('delete_permanent')) || !$auth->acl_get('m_delete', $forum_id)) ? true : false;

				if (!count($topic_ids))
				{
					trigger_error('NO_TOPIC_SELECTED');
				}

				mcp_delete_topic($topic_ids, $soft_delete, $request->variable('delete_reason', '', true));
			break;

			default:
				/**
				* This event allows you to handle custom quickmod options
				*
				* @event core.modify_quickmod_actions
				* @var	string	action		Topic quick moderation action name
				* @var	bool	quickmod	Flag indicating whether MCP is in quick moderation mode
				* @since 3.1.0-a4
				* @changed 3.1.0-RC4 Added variables: action, quickmod
				*/
				$vars = array('action', 'quickmod');
				extract($phpbb_dispatcher->trigger_event('core.modify_quickmod_actions', compact($vars)));
			break;
		}

		switch ($mode)
		{        
			case 'forum_view_search':
				include($phpbb_root_path . 'includes/mcp/mcp_forum_search.' . $phpEx);

				$user->add_lang('viewforum');

				$forum_id = $request->variable('f', 0);
        
				$forum_info = phpbb_get_forum_data($forum_id, 'm_', true);
        
				if (!count($forum_info))
				{
					trigger_error('TOPIC_NOT_EXIST');
					return;
				}

				$forum_info = $forum_info[$forum_id];

				mcp_forum_view_search($id, $mode, $action, $forum_info, $request->variable('search_criteria', '', true));

				$this->tpl_name = 'mcp_forum_search';
				$this->page_title = 'MCP_MAIN_FORUM_VIEW';
			break;

			default:
				if ($quickmod)
				{
					switch ($action)
					{
						case 'move':
						case 'delete_topic':
							trigger_error('TOPIC_NOT_EXIST');
						break;
					}
				}

				trigger_error('NO_MODE', E_USER_ERROR);
			break;
		}
	}
}

/**
* Move Topic
*/
function mcp_move_topic($topic_ids)
{
	global $auth, $user, $db, $template, $phpbb_log, $request, $phpbb_dispatcher;
	global $phpEx, $phpbb_root_path;

	// Here we limit the operation to one forum only
	$forum_id = phpbb_check_ids($topic_ids, TOPICS_TABLE, 'topic_id', array('m_move'), true);

	if ($forum_id === false)
	{
		return;
	}

	$to_forum_id = $request->variable('to_forum_id', 0);
	$redirect = $request->variable('redirect', build_url(array('action', 'quickmod')));
	$additional_msg = $success_msg = '';

	$s_hidden_fields = build_hidden_fields(array(
		'topic_id_list'	=> $topic_ids,
		'f'				=> $forum_id,
		'action'		=> 'move',
		'redirect'		=> $redirect)
	);

	if ($to_forum_id)
	{
		$forum_data = phpbb_get_forum_data($to_forum_id, 'f_post');

		if (!count($forum_data))
		{
			$additional_msg = $user->lang['FORUM_NOT_EXIST'];
		}
		else
		{
			$forum_data = $forum_data[$to_forum_id];

			if ($forum_data['forum_type'] != FORUM_POST)
			{
				$additional_msg = $user->lang['FORUM_NOT_POSTABLE'];
			}
			else if (!$auth->acl_get('f_post', $to_forum_id) || (!$auth->acl_get('m_approve', $to_forum_id) && !$auth->acl_get('f_noapprove', $to_forum_id)))
			{
				$additional_msg = $user->lang['USER_CANNOT_POST'];
			}
			else if ($forum_id == $to_forum_id)
			{
				$additional_msg = $user->lang['CANNOT_MOVE_SAME_FORUM'];
			}
		}
	}
	else if (isset($_POST['confirm']))
	{
		$additional_msg = $user->lang['FORUM_NOT_EXIST'];
	}

	if (!$to_forum_id || $additional_msg)
	{
		$request->overwrite('confirm', null, \phpbb\request\request_interface::POST);
		$request->overwrite('confirm_key', null);
	}

	if (confirm_box(true))
	{
		$topic_data = phpbb_get_topic_data($topic_ids);
		$leave_shadow = (isset($_POST['move_leave_shadow'])) ? true : false;

		$forum_sync_data = array();

		$forum_sync_data[$forum_id] = current($topic_data);
		$forum_sync_data[$to_forum_id] = $forum_data;

		$topics_moved = $topics_moved_unapproved = $topics_moved_softdeleted = 0;
		$posts_moved = $posts_moved_unapproved = $posts_moved_softdeleted = 0;

		foreach ($topic_data as $topic_id => $topic_info)
		{
			if ($topic_info['topic_visibility'] == ITEM_APPROVED)
			{
				$topics_moved++;
			}
			else if ($topic_info['topic_visibility'] == ITEM_UNAPPROVED || $topic_info['topic_visibility'] == ITEM_REAPPROVE)
			{
				$topics_moved_unapproved++;
			}
			else if ($topic_info['topic_visibility'] == ITEM_DELETED)
			{
				$topics_moved_softdeleted++;
			}

			$posts_moved += $topic_info['topic_posts_approved'];
			$posts_moved_unapproved += $topic_info['topic_posts_unapproved'];
			$posts_moved_softdeleted += $topic_info['topic_posts_softdeleted'];
		}

		$db->sql_transaction('begin');

		// Move topics, but do not resync yet
		move_topics($topic_ids, $to_forum_id, false);

		if ($request->is_set_post('move_lock_topics') && $auth->acl_get('m_lock', $to_forum_id))
		{
			$sql = 'UPDATE ' . TOPICS_TABLE . '
				SET topic_status = ' . ITEM_LOCKED . '
				WHERE ' . $db->sql_in_set('topic_id', $topic_ids);
			$db->sql_query($sql);
		}

		$shadow_topics = 0;
		$forum_ids = array($to_forum_id);
		foreach ($topic_data as $topic_id => $row)
		{
			// Get the list of forums to resync
			$forum_ids[] = $row['forum_id'];

			// We add the $to_forum_id twice, because 'forum_id' is updated
			// when the topic is moved again later.
			$phpbb_log->add('mod', $user->data['user_id'], $user->ip, 'LOG_MOVE', false, array(
				'forum_id'		=> (int) $to_forum_id,
				'topic_id'		=> (int) $topic_id,
				$row['forum_name'],
				$forum_data['forum_name'],
				(int) $row['forum_id'],
				(int) $forum_data['forum_id'],
			));

			// Leave a redirection if required and only if the topic is visible to users
			if ($leave_shadow && $row['topic_visibility'] == ITEM_APPROVED && $row['topic_type'] != POST_GLOBAL)
			{
				$shadow = array(
					'forum_id'				=>	(int) $row['forum_id'],
					'icon_id'				=>	(int) $row['icon_id'],
					'topic_attachment'		=>	(int) $row['topic_attachment'],
					'topic_visibility'		=>	ITEM_APPROVED, // a shadow topic is always approved
					'topic_reported'		=>	0, // a shadow topic is never reported
					'topic_title'			=>	(string) $row['topic_title'],
					'topic_poster'			=>	(int) $row['topic_poster'],
					'topic_time'			=>	(int) $row['topic_time'],
					'topic_time_limit'		=>	(int) $row['topic_time_limit'],
					'topic_views'			=>	(int) $row['topic_views'],
					'topic_posts_approved'	=>	(int) $row['topic_posts_approved'],
					'topic_posts_unapproved'=>	(int) $row['topic_posts_unapproved'],
					'topic_posts_softdeleted'=>	(int) $row['topic_posts_softdeleted'],
					'topic_status'			=>	ITEM_MOVED,
					'topic_type'			=>	POST_NORMAL,
					'topic_first_post_id'	=>	(int) $row['topic_first_post_id'],
					'topic_first_poster_colour'=>(string) $row['topic_first_poster_colour'],
					'topic_first_poster_name'=>	(string) $row['topic_first_poster_name'],
					'topic_last_post_id'	=>	(int) $row['topic_last_post_id'],
					'topic_last_poster_id'	=>	(int) $row['topic_last_poster_id'],
					'topic_last_poster_colour'=>(string) $row['topic_last_poster_colour'],
					'topic_last_poster_name'=>	(string) $row['topic_last_poster_name'],
					'topic_last_post_subject'=>	(string) $row['topic_last_post_subject'],
					'topic_last_post_time'	=>	(int) $row['topic_last_post_time'],
					'topic_last_view_time'	=>	(int) $row['topic_last_view_time'],
					'topic_moved_id'		=>	(int) $row['topic_id'],
					'topic_bumped'			=>	(int) $row['topic_bumped'],
					'topic_bumper'			=>	(int) $row['topic_bumper'],
					'poll_title'			=>	(string) $row['poll_title'],
					'poll_start'			=>	(int) $row['poll_start'],
					'poll_length'			=>	(int) $row['poll_length'],
					'poll_max_options'		=>	(int) $row['poll_max_options'],
					'poll_last_vote'		=>	(int) $row['poll_last_vote']
				);

				/**
				* Perform actions before shadow topic is created.
				*
				* @event core.mcp_main_modify_shadow_sql
				* @var	array	shadow	SQL array to be used by $db->sql_build_array
				* @var	array	row		Topic data
				* @since 3.1.11-RC1
				* @changed 3.1.11-RC1 Added variable: row
				*/
				$vars = array(
					'shadow',
					'row',
				);
				extract($phpbb_dispatcher->trigger_event('core.mcp_main_modify_shadow_sql', compact($vars)));

				$db->sql_query('INSERT INTO ' . TOPICS_TABLE . $db->sql_build_array('INSERT', $shadow));

				// Shadow topics only count on new "topics" and not posts... a shadow topic alone has 0 posts
				$shadow_topics++;
			}
		}
		unset($topic_data);

		$sync_sql = array();
		if ($posts_moved)
		{
			$sync_sql[$to_forum_id][] = 'forum_posts_approved = forum_posts_approved + ' . (int) $posts_moved;
			$sync_sql[$forum_id][] = 'forum_posts_approved = forum_posts_approved - ' . (int) $posts_moved;
		}
		if ($posts_moved_unapproved)
		{
			$sync_sql[$to_forum_id][] = 'forum_posts_unapproved = forum_posts_unapproved + ' . (int) $posts_moved_unapproved;
			$sync_sql[$forum_id][] = 'forum_posts_unapproved = forum_posts_unapproved - ' . (int) $posts_moved_unapproved;
		}
		if ($posts_moved_softdeleted)
		{
			$sync_sql[$to_forum_id][] = 'forum_posts_softdeleted = forum_posts_softdeleted + ' . (int) $posts_moved_softdeleted;
			$sync_sql[$forum_id][] = 'forum_posts_softdeleted = forum_posts_softdeleted - ' . (int) $posts_moved_softdeleted;
		}

		if ($topics_moved)
		{
			$sync_sql[$to_forum_id][] = 'forum_topics_approved = forum_topics_approved + ' . (int) $topics_moved;
			if ($topics_moved - $shadow_topics > 0)
			{
				$sync_sql[$forum_id][] = 'forum_topics_approved = forum_topics_approved - ' . (int) ($topics_moved - $shadow_topics);
			}
		}
		if ($topics_moved_unapproved)
		{
			$sync_sql[$to_forum_id][] = 'forum_topics_unapproved = forum_topics_unapproved + ' . (int) $topics_moved_unapproved;
			$sync_sql[$forum_id][] = 'forum_topics_unapproved = forum_topics_unapproved - ' . (int) $topics_moved_unapproved;
		}
		if ($topics_moved_softdeleted)
		{
			$sync_sql[$to_forum_id][] = 'forum_topics_softdeleted = forum_topics_softdeleted + ' . (int) $topics_moved_softdeleted;
			$sync_sql[$forum_id][] = 'forum_topics_softdeleted = forum_topics_softdeleted - ' . (int) $topics_moved_softdeleted;
		}

		$success_msg = (count($topic_ids) == 1) ? 'TOPIC_MOVED_SUCCESS' : 'TOPICS_MOVED_SUCCESS';

		foreach ($sync_sql as $forum_id_key => $array)
		{
			$sql = 'UPDATE ' . FORUMS_TABLE . '
				SET ' . implode(', ', $array) . '
				WHERE forum_id = ' . $forum_id_key;
			$db->sql_query($sql);
		}

		$db->sql_transaction('commit');

		sync('forum', 'forum_id', array($forum_id, $to_forum_id));
	}
	else
	{
		$template->assign_vars(array(
			'S_FORUM_SELECT'		=> make_forum_select($to_forum_id, $forum_id, false, true, true, true),
			'S_CAN_LEAVE_SHADOW'	=> true,
			'S_CAN_LOCK_TOPIC'		=> ($auth->acl_get('m_lock', $to_forum_id)) ? true : false,
			'ADDITIONAL_MSG'		=> $additional_msg)
		);

		confirm_box(false, 'MOVE_TOPIC' . ((count($topic_ids) == 1) ? '' : 'S'), $s_hidden_fields, 'mcp_move.html');
	}

	$redirect = $request->variable('redirect', "index.$phpEx");
	$redirect = reapply_sid($redirect);

	if (!$success_msg)
	{
		redirect($redirect);
	}
	else
	{
		meta_refresh(3, $redirect);

		$message = $user->lang[$success_msg];
		$message .= '<br /><br />' . sprintf($user->lang['RETURN_PAGE'], '<a href="' . $redirect . '">', '</a>');
		$message .= '<br /><br />' . sprintf($user->lang['RETURN_FORUM'], '<a href="' . append_sid("{$phpbb_root_path}viewforum.$phpEx", "f=$forum_id") . '">', '</a>');
		$message .= '<br /><br />' . sprintf($user->lang['RETURN_NEW_FORUM'], '<a href="' . append_sid("{$phpbb_root_path}viewforum.$phpEx", "f=$to_forum_id") . '">', '</a>');

		trigger_error($message);
	}
}

/**
* Delete Topics
*/
function mcp_delete_topic($topic_ids, $is_soft = false, $soft_delete_reason = '', $action = 'delete_topic')
{
	global $auth, $user, $db, $phpEx, $phpbb_root_path, $request, $phpbb_container, $phpbb_log;

	$check_permission = ($is_soft) ? 'm_softdelete' : 'm_delete';
	if (!phpbb_check_ids($topic_ids, TOPICS_TABLE, 'topic_id', array($check_permission)))
	{
		return;
	}

	$redirect = $request->variable('redirect', build_url(array('action', 'quickmod')));
	$forum_id = $request->variable('f', 0);

	$s_hidden_fields = array(
		'topic_id_list'	=> $topic_ids,
		'f'				=> $forum_id,
		'action'		=> $action,
		'redirect'		=> $redirect,
	);
	$success_msg = '';

	if (confirm_box(true))
	{
		$success_msg = (count($topic_ids) == 1) ? 'TOPIC_DELETED_SUCCESS' : 'TOPICS_DELETED_SUCCESS';

		$data = phpbb_get_topic_data($topic_ids);

		foreach ($data as $topic_id => $row)
		{
			if ($row['topic_moved_id'])
			{
				$phpbb_log->add('mod', $user->data['user_id'], $user->ip, 'LOG_DELETE_SHADOW_TOPIC', false, array(
					'forum_id' => $row['forum_id'],
					'topic_id' => $topic_id,
					$row['topic_title']
				));
			}
			else
			{
				// Only soft delete non-shadow topics
				if ($is_soft)
				{
					/* @var $phpbb_content_visibility \phpbb\content_visibility */
					$phpbb_content_visibility = $phpbb_container->get('content.visibility');
					$return = $phpbb_content_visibility->set_topic_visibility(ITEM_DELETED, $topic_id, $row['forum_id'], $user->data['user_id'], time(), $soft_delete_reason);
					if (!empty($return))
					{
						$phpbb_log->add('mod', $user->data['user_id'], $user->ip, 'LOG_SOFTDELETE_TOPIC', false, array(
							'forum_id' => $row['forum_id'],
							'topic_id' => $topic_id,
							$row['topic_title'],
							$row['topic_first_poster_name'],
							$soft_delete_reason
						));
					}
				}
				else
				{
					$phpbb_log->add('mod', $user->data['user_id'], $user->ip, 'LOG_DELETE_TOPIC', false, array(
						'forum_id' => $row['forum_id'],
						'topic_id' => $topic_id,
						$row['topic_title'],
						$row['topic_first_poster_name'],
						$soft_delete_reason
					));
				}
			}
		}

		if (!$is_soft)
		{
			delete_topics('topic_id', $topic_ids);
		}
	}
	else
	{
		global $template;

		$user->add_lang('posting');

		// If there are only shadow topics, we neither need a reason nor softdelete
		$sql = 'SELECT topic_id
			FROM ' . TOPICS_TABLE . '
			WHERE ' . $db->sql_in_set('topic_id', $topic_ids) . '
				AND topic_moved_id = 0';
		$result = $db->sql_query_limit($sql, 1);
		$only_shadow = !$db->sql_fetchfield('topic_id');
		$db->sql_freeresult($result);

		$only_softdeleted = false;
		if (!$only_shadow && $auth->acl_get('m_delete', $forum_id) && $auth->acl_get('m_softdelete', $forum_id))
		{
			// If there are only soft deleted topics, we display a message why the option is not available
			$sql = 'SELECT topic_id
				FROM ' . TOPICS_TABLE . '
				WHERE ' . $db->sql_in_set('topic_id', $topic_ids) . '
					AND topic_visibility <> ' . ITEM_DELETED;
			$result = $db->sql_query_limit($sql, 1);
			$only_softdeleted = !$db->sql_fetchfield('topic_id');
			$db->sql_freeresult($result);
		}

		$template->assign_vars(array(
			'S_SHADOW_TOPICS'					=> $only_shadow,
			'S_SOFTDELETED'						=> $only_softdeleted,
			'S_TOPIC_MODE'						=> true,
			'S_ALLOWED_DELETE'					=> $auth->acl_get('m_delete', $forum_id),
			'S_ALLOWED_SOFTDELETE'				=> $auth->acl_get('m_softdelete', $forum_id),
			'DELETE_TOPIC_PERMANENTLY_EXPLAIN'	=> $user->lang('DELETE_TOPIC_PERMANENTLY', count($topic_ids)),
		));

		$l_confirm = (count($topic_ids) == 1) ? 'DELETE_TOPIC' : 'DELETE_TOPICS';
		if ($only_softdeleted)
		{
			$l_confirm .= '_PERMANENTLY';
			$s_hidden_fields['delete_permanent'] = '1';
		}
		else if ($only_shadow || !$auth->acl_get('m_softdelete', $forum_id))
		{
			$s_hidden_fields['delete_permanent'] = '1';
		}

		confirm_box(false, $l_confirm, build_hidden_fields($s_hidden_fields), 'confirm_delete_body.html');
	}

	$topic_id = $request->variable('t', 0);
	if (!$request->is_set('quickmod', \phpbb\request\request_interface::REQUEST))
	{
		$redirect = $request->variable('redirect', "index.$phpEx");
		$redirect = reapply_sid($redirect);
		$redirect_message = 'PAGE';
	}
	else if ($is_soft && $topic_id)
	{
		$redirect = append_sid("{$phpbb_root_path}viewtopic.$phpEx", 't=' . $topic_id);
		$redirect_message = 'TOPIC';
	}
	else
	{
		$redirect = append_sid("{$phpbb_root_path}viewforum.$phpEx", 'f=' . $forum_id);
		$redirect_message = 'FORUM';
	}

	if (!$success_msg)
	{
		redirect($redirect);
	}
	else
	{
		meta_refresh(3, $redirect);
		trigger_error($user->lang[$success_msg] . '<br /><br />' . sprintf($user->lang['RETURN_' . $redirect_message], '<a href="' . $redirect . '">', '</a>'));
	}
}
