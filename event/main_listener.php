<?php
/**
 *
 * Custom Styles. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2022, Duome Forum
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace luoning\emaillogin\event;

/**
 * @ignore
 */

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class main_listener implements EventSubscriberInterface
{
	public static function getSubscribedEvents()
	{
		return [
			'core.auth_login_session_create_before' => 'login_with_email',
			'core.page_header' => 'modify_ui_strings',
		];
	}

	protected $config;
	protected $language;
	protected $template;
	protected $user;
	protected $request;
	protected $symfony_request;
	protected $phpbb_container;
	protected $db;

	public function __construct(
		\phpbb\config\config $config,
		\phpbb\language\language $language,
		\phpbb\template\template $template,
		\phpbb\user $user,
		\phpbb\request\request $request,
		\phpbb\symfony_request  $symfony_request,
		ContainerInterface  $phpbb_container,
		\phpbb\db\driver\driver_interface $db
	)
	{
		$this->config = $config;
		$this->language = $language;
		$this->template = $template;
		$this->user = $user;
		$this->request = $request;
		$this->symfony_request = $symfony_request;
		$this->phpbb_container = $phpbb_container;
		$this->db = $db;
	}

	/**
	 * Enables logging in with email as an alternative to username
	 */
	public function login_with_email(\phpbb\event\data $event)
	{
		if (!$this->config['allow_emailreuse']
			&& $event['login']['status'] === LOGIN_ERROR_USERNAME)
		{
			$provider = $this->phpbb_container->get(
				'auth.provider_collection'
			)->get_provider();

			$email = trim($this->symfony_request->request->get('username'));
			$password = $this->symfony_request->request->get('password');

			$sql = 'SELECT *
				FROM ' . USERS_TABLE . "
				WHERE user_email = '" . $this->db->sql_escape($email) . "'";

			$result = $this->db->sql_query($sql);
			$row = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);

			if ($row && $row['username_clean'] !== utf8_clean_string($email))
			{
				$event['login'] =  $provider->login(
					$row['username'],
					$password
				);
			}
		}
	}

	/**
	 * Set login prompt to "Username / email" or translated equivalent
	 */
	public function modify_ui_strings()
	{
		$path = preg_split(
			'/[\/.]/',
			substr(
				$this->symfony_request->getBaseUrl(),
				strlen($this->symfony_request->getBasePath())
			)
		)[1];

		$path_matches = !$path
			|| $path === 'index'
			|| ($path === 'ucp'
				&& $this->symfony_request->query->get('mode') === 'login');

		if (!$this->config['allow_emailreuse']
			&& $path_matches
			&& (int) $this->user->data['user_id'] === ANONYMOUS
			&& !$this->request->is_set_post('agreed')
			&& $this->request->variable('mode', '') !== 'sendpassword')
		{
			$login_prompt = $this->language->lang('USERNAME')
				. ' / '
				. mb_strtolower($this->language->lang('EMAIL'));

			$this->template->assign_var('L_USERNAME', $login_prompt);
		}
	}
}
