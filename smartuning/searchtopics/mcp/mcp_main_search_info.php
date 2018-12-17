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

class mcp_main_search_info
{
	function module()
	{
		return array(
			'filename'	=> 'mcp_main_search',
			'title'		=> 'MCP_MAIN_SEARCH',
			'modes'		=> array(
				'forum_view_search'	=> array('title' => 'MCP_MAIN_FORUM_VIEW', 'auth' => 'acl_m_,$id', 'cat' => array('MCP_MAIN_SEARCH')),
			),
		);
	}

	function install()
	{
	}

	function uninstall()
	{
	}
}
