<?php

/**
 * WP_SweepBright_Contact.
 *
 * This class sends emails on behalf of SweepBright, and stores form settings,
 * such as `from`, `to`, and so on.
 *
 * @package    WP_SweepBright_Contact
 */

class WP_SweepBright_Contact
{

	public function __construct()
	{
		$this->register_shortcode();
		$this->verify_recaptcha();

		add_action('wp_footer', 'add_recaptcha_to_footer');
		function add_recaptcha_to_footer()
		{
			WP_SweepBright_Contact::add_recaptcha();
		}
	}

	public static function add_recaptcha()
	{
		$script = '';
		$script .= '<script id="wp-sweepbright-recaptcha-load" src="https://www.google.com/recaptcha/api.js?render=' . WP_SweepBright_Helpers::settings_form()['recaptcha_site_key'] . '"></script>';
		$script .= '<script id="wp-sweepbright-recaptcha-exec">';
		$script .= 'grecaptcha.ready(function () {';
		$script .= '	grecaptcha.execute(\'' . WP_SweepBright_Helpers::settings_form()['recaptcha_site_key'] . '\', { action: \'contact\' }).then(function (token) {';
		$script .= '		window.WPContactRecaptcha = token;';
		$script .= '		if (document.getElementById(\'recaptchaResponse\')) {';
		$script .= '			var recaptchaResponse = document.getElementById(\'recaptchaResponse\');';
		$script .= '			recaptchaResponse.value = token;';
		$script .= '		}';
		$script .= '	});';
		$script .= '});';
		$script .= '</script>';
		echo $script;
	}

	public function verify_recaptcha()
	{
		if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recaptcha_response'])) {
			$recaptcha_url = 'https://www.google.com/recaptcha/api/siteverify';
			$recaptcha_secret = WP_SweepBright_Helpers::settings_form()['recaptcha_secret_key'];
			$recaptcha_response = $_POST['recaptcha_response'];

			$recaptcha = file_get_contents($recaptcha_url . '?secret=' . $recaptcha_secret . '&response=' . $recaptcha_response);
			$recaptcha = json_decode($recaptcha);

			if ($recaptcha->success) {
				$this->submit_estate_form();
				$this->submit_general_form();
			} else {
				$this->emit_event('wp_contact_estate_error');
			}
		}
	}

	public function register_shortcode()
	{
		function contact_estate_shortcode()
		{
			$form = "";
			$form .= "<form name=\"contact-estate\" method=\"post\" action=\"\">";
			$form .= stripslashes(WP_SweepBright_Helpers::contact_form()['contact_request_estate_form']);
			$form .= "</form>";
			return $form;
		}
		add_shortcode('contact-request-estate', 'contact_estate_shortcode');

		function contact_general_shortcode()
		{
			$form = "";
			$form .= "<form name=\"contact-general\" method=\"post\" action=\"\">";
			$form .= stripslashes(WP_SweepBright_Helpers::contact_form()['contact_request_general_form']);
			$form .= "</form>";
			return $form;
		}
		add_shortcode('contact-request-general', 'contact_general_shortcode');
	}

	public function validate_input($data)
	{
		$data = trim($data);
		return $data;
	}

	public function emit_event($event)
	{
		add_action('wp_head', function () use ($event) {
			echo "
			<script type=\"text/javascript\">
			// Create the event.
			var sb_event = document.createEvent('Event');

			// Define that the event name is 'build'.
			sb_event.initEvent('$event', true, true);

			// target can be any Element or other EventTarget.
			document.dispatchEvent(sb_event);
			</script>
			";
		});
	}

	public function parse_template($template, $form)
	{
		$output = str_replace('[title]', $form['title'], $template);
		$output = str_replace('[first_name]', $form['first_name'], $output);
		$output = str_replace('[last_name]', $form['last_name'], $output);
		$output = str_replace('[email]', $form['email'], $output);
		$output = str_replace('[phone]', $form['phone'], $output);
		$output = str_replace('[message]', $form['message'], $output);
		return $output;
	}

	public function autorespond($form)
	{
		$to = WP_SweepBright_Helpers::contact_form()['autoresponder']['to'];
		$subject = $this->parse_template(WP_SweepBright_Helpers::contact_form()['autoresponder']['subject'], $form);
		$body = $this->parse_template(WP_SweepBright_Helpers::contact_form()['autoresponder']['message'], $form);
		$headers = [
			'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
			'Reply-To: ' . $form['first_name'] . ' ' . $form['last_name'] . ' <' . $form['email'] . '>',
		];
		if (WP_SweepBright_Helpers::contact_form()['autoresponder']['cc']) {
			$cc = explode(',', WP_SweepBright_Helpers::contact_form()['autoresponder']['cc']);
			foreach ($cc as &$cc_mail) {
				$cc_mail = trim($cc_mail);
				$headers[] = "Cc: $cc_mail";
			}
		}
		$headers[] = 'Content-Type: text/html; charset=UTF-8';
		wp_mail($to, $subject, $body, $headers);
	}

	public function submit_estate_form()
	{
		if (isset($_POST['submit-contact-estate']) && $_SERVER['REQUEST_METHOD'] == 'POST') {
			$locale = $GLOBALS['wp_sweepbright_config']['default_locale'];
			$id = get_field('estate', get_the_ID())['id'];
			$form = [
				'title' => get_the_title(),
				'first_name' => $this->validate_input($_POST['first_name']),
				'last_name' => $this->validate_input($_POST['last_name']),
				'email' => $this->validate_input($_POST['email']),
				'phone' => $this->validate_input($_POST['phone']),
				'message' => $this->validate_input($_POST['message']),
				'locale' => $this->validate_input($_POST['locale']),
			];

			$estate_url = WP_SweepBright_Controller_Hook::get_client()->request('POST', "estates/$id/contacts", [
				'verify' => false,
				'json' => [
					'first_name' => $form['first_name'],
					'last_name' => $form['last_name'],
					'email' => $form['email'],
					'phone' => $form['phone'],
					'message' => $form['message'],
					'locale' => $form['locale'],
				],
			]);

			$this->autorespond($form);
			$this->emit_event('wp_contact_estate_sent');
		}
	}

	public function submit_general_form()
	{
		if (isset($_POST['submit-contact-general']) && $_SERVER['REQUEST_METHOD'] == 'POST') {
			$locale = $GLOBALS['wp_sweepbright_config']['default_locale'];
			$form = [
				'title' => '-',
				'first_name' => $this->validate_input($_POST['first_name']),
				'last_name' => $this->validate_input($_POST['last_name']),
				'email' => $this->validate_input($_POST['email']),
				'phone' => $this->validate_input($_POST['phone']),
				'message' => $this->validate_input($_POST['message']),
				'locale' => $this->validate_input($_POST['locale']),
			];

			if (isset($_POST['negotiation']) && $_POST['negotiation'] !== '') {
				$form['negotiation'] = $this->validate_input($_POST['negotiation']);
			} else {
				$form['negotiation'] = false;
			}

			if (isset($_POST['types']) && $_POST['types'] !== '') {
				$form['types'] = $_POST['types'];
			} else {
				$form['types'] = [];
			}

			if (isset($_POST['max_price']) && $_POST['max_price'] !== '') {
				$form['max_price'] = $this->validate_input($_POST['max_price']);
			} else {
				$form['max_price'] = 0;
			}

			if (isset($_POST['min_price']) && $_POST['min_price'] !== '') {
				$form['min_price'] = $this->validate_input($_POST['min_price']);
			} else {
				$form['min_price'] = 0;
			}

			if (isset($_POST['min_rooms']) && $_POST['min_rooms'] !== '') {
				$form['min_rooms'] = $this->validate_input($_POST['min_rooms']);
			} else {
				$form['min_rooms'] = 0;
			}

			if (isset($_POST['country']) && $_POST['country'] !== '') {
				$form['country'] = $this->validate_input($_POST['country']);
			} else {
				$form['country'] = '';
			}

			if (isset($_POST['postal_codes']) && $_POST['postal_codes'] !== '') {
				$form['postal_codes'] = explode(',', $_POST['postal_codes']);

				if (!is_array($form['postal_codes'])) {
					$form['postal_codes'] = [$form['postal_codes']];
				}
			} else {
				$form['postal_codes'] = [];
			}

			$estate_url = WP_SweepBright_Controller_Hook::get_client()->request('POST', "contacts", [
				'verify' => false,
				'json' => [
					'first_name' => $form['first_name'],
					'last_name' => $form['last_name'],
					'email' => $form['email'],
					'phone' => $form['phone'],
					'message' => $form['message'],
					'locale' => $form['locale'],

					'preferences' => [
						'negotiation' => $form['negotiation'],
						'types' => $form['types'],
						'max_price' => $form['max_price'],
						'min_price' => $form['min_price'],
						'min_rooms' => $form['min_rooms'],
					],

					'location_preference' => [
						'country' => $form['country'],
						'postal_codes' => $form['postal_codes'],
					]
				],
			]);


			$this->autorespond($form);
			$this->emit_event('wp_contact_estate_sent');
		}
	}
}
