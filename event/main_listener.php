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
		\phpbb\symfony_request $symfony_request,
		ContainerInterface $phpbb_container,
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

	const LAST_ERR_COOKIE_KEY = 'email_login_ext_last_err';
	const LAST_ERR_EMAIL = 'EMAIL';

	/**
	 * Enables logging in with email as an alternative to username
	 */
	public function login_with_email(\phpbb\event\data $event)
	{
		if (!$this->config['allow_emailreuse']
			&& $event['login']['status'] === LOGIN_ERROR_USERNAME)
		{
			$provider = $this->phpbb_container
				->get('auth.provider_collection')
				->get_provider();

			$email = trim($this->symfony_request->request->get('username'));
			$password = $this->symfony_request->request->get('password');

			if (filter_var($email, FILTER_VALIDATE_EMAIL))
			{
				$login = $event['login'];

				$sql = "SELECT * FROM " . USERS_TABLE . "
					WHERE user_email = '" . $this->db->sql_escape($email) . "'";

				$result = $this->db->sql_query($sql);
				$row = $this->db->sql_fetchrow($result);
				$this->db->sql_freeresult($result);

				if ($row
					&& $row['username_clean'] !== utf8_clean_string($email))
				{
					$login = $provider->login($row['username'], $password);
				}
				else
				{
					$this->request->overwrite(
						self::LAST_ERR_COOKIE_KEY,
						self::LAST_ERR_EMAIL,
						\phpbb\request\request_interface::COOKIE
					);
				}

				$event['login'] = $login;
			}
		}
	}

	/**
	 * Modify translated UI strings in templates
	 */
	public function modify_ui_strings()
	{
		$path = preg_split('/[\/.]/', substr(
			$this->symfony_request->getBaseUrl(),
			strlen($this->symfony_request->getBasePath())
		))[1];

		$path_matches = !$path
			|| $path === 'index'
			|| ($path === 'ucp' &&
				$this->symfony_request->query->get('mode') === 'login');

		if (!$this->config['allow_emailreuse']
			&& $path_matches
			&& (int) $this->user->data['user_id'] === ANONYMOUS
			&& !$this->request->is_set_post('agreed')
			&& $this->request->variable('mode', '') !== 'sendpassword')
		{
			$username_or_password =
				$this->language->lang('USERNAME') .
				'/' .
				"\u{200b}" . // zero-width space
				mb_strtolower($this->language->lang('EMAIL'));

			// Set login prompt to "Username/email" or translated equivalent
			$this->template->assign_var('L_USERNAME', $username_or_password);

			$login_err = $this->template->retrieve_var('LOGIN_ERROR');

			$email_login_last_err = $this->request->variable(
				self::LAST_ERR_COOKIE_KEY,
				'',
				false,
				\phpbb\request\request_interface::COOKIE
			);

			// Replace all instances of "username" in text content of login
			// error with "email" or translated equivalent
			if ($login_err && $email_login_last_err === self::LAST_ERR_EMAIL)
			{
				// odd indexes are HTML tags;
				// even indexes are text content
				$segments = preg_split(
					'/(<[^>]+>)/',
					$login_err,
					-1,
					PREG_SPLIT_DELIM_CAPTURE
				);

				$matcher = '/'
					. preg_quote($this->language->lang('USERNAME'), '/')
					. '/iu';

				$replacement = mb_strtolower($this->language->lang('EMAIL'));

				// map with indexes
				$login_err = implode(array_map(
					function ($segment, $idx) use($matcher, $replacement) {
						return $idx % 2
							? // odd index: is code block or HTML tag content
							$segment
							: // even index: is text content
							preg_replace(
								$matcher,
								$replacement,
								$segment
							);
					},
					$segments,
					array_keys($segments)
				));

				$this->template->assign_var('LOGIN_ERROR', $login_err);
			}
		}
	}
}
