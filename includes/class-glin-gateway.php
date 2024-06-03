<?php

/**
 * WC_Glin_Gateway
 *
 *
 *
 * @class       WC_Glin_Gateway
 * @extends     WC_Payment_Gateway
 * @version     2.1.0
 * @package     WooCommerce\Classes\Payment
 */
class WC_Glin_Gateway extends WC_Payment_Gateway
{

	/**
	 * Gateway instructions that will be added to the thank you page and emails.
	 *
	 * @var string
	 */
	public $instructions;
	public $status_when_waiting;

	public $title;
	public $description;
	public $id;
	public $icon;
	public $method_title;
	public $method_description;
	public $has_fields;
	public $form_fields;

	public $token;

    

	/**
	 * Enable for shipping methods.
	 *
	 * @var array
	 */
	public $enable_for_methods;

	/**
	 * Constructor for the gateway.
	 */
	public function __construct()
	{
		// Setup general properties.
		$this->setup_properties();

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Get settings.
		$this->title              = $this->get_option('title');
        $this->token              = $this->get_option('token');
		$this->description        = $this->get_option('description');
		$this->instructions       = $this->get_option('instructions');
		$this->enable_for_methods = $this->get_option('enable_for_methods', array());

		// Actions.
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

		add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));

		// Customer Emails.
		add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 3);
	}



	/**
	 * Setup general properties for the gateway.
	 */
	protected function setup_properties()
	{
		$this->id                 = 'glin-plugin';
        $this->token         = __('Adicionar Token de integração', 'glin-plugin');
		// $this->icon               = apply_filters('glin-plugin', plugins_url('../assets/icon-pix.png', __FILE__));
		$this->method_title       = __('Glin', 'glin-plugin');
		$this->method_description = __('Receba pagamentos em Pix utilizando sua conta Glin', 'glin-plugin');
		$this->has_fields         = false;
		$this->instructions 	  = __(' ', 'glin-plugin');
	}



	/**
	 * Initialise Gateway Settings Form Fields.
	 */
	public function init_form_fields()
	{
		$this->form_fields = array(
			'enabled'            => array(
				'title'       => __('Ativar/Desativar', 'glin-plugin'),
				'label'       => __('Ativar método de pagamento - Glin', 'glin-plugin'),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no',
			),
            'token'              => array(
				'title'       => __('Adicionar Token de integração', 'glin-plugin'),
				'type'        => 'text',
			),
			'title'              => array(
				'title'       => __('Título', 'glin-plugin'),
				'type'        => 'safe_text',
				'description' => __('Título que o cliente verá na tela de pagamento', 'glin-plugin'),
				'default'     => __('Glin', 'glin-plugin'),
				'desc_tip'    => true,
			),
			'description'        => array(
				'title'       => __('Descrição', 'glin-plugin'),
				'type'        => 'textarea',
				'description' => __('Descrição do método de pagamento', 'glin-plugin'),
				'default'     => __('Realize o pagamento no Pix ou Cartão!', 'glin-plugin'),
				'desc_tip'    => true,
			),
		);
	}



	/**
	 * Check If The Gateway Is Available For Use.
	 *
	 * @return bool
	 */
	public function is_available()
	{
		$order          = null;
		$needs_shipping = false;

		// Test if shipping is needed first.
		if (WC()->cart && WC()->cart->needs_shipping()) {
			$needs_shipping = true;
		} elseif (is_page(wc_get_page_id('checkout')) && 0 < get_query_var('order-pay')) {
			$order_id = absint(get_query_var('order-pay'));
			$order    = wc_get_order($order_id);

			// Test if order needs shipping.
			if ($order && 0 < count($order->get_items())) {
				foreach ($order->get_items() as $item) {
					$_product = $item->get_product();
					if ($_product && $_product->needs_shipping()) {
						$needs_shipping = true;
						break;
					}
				}
			}
		}

		$needs_shipping = apply_filters('woocommerce_cart_needs_shipping', $needs_shipping);

		// Only apply if all packages are being shipped via chosen method, or order is virtual.
		if (!empty($this->enable_for_methods) && $needs_shipping) {
			$order_shipping_items            = is_object($order) ? $order->get_shipping_methods() : false;
			$chosen_shipping_methods_session = WC()->session->get('chosen_shipping_methods');

			if ($order_shipping_items) {
				$canonical_rate_ids = $this->get_canonical_order_shipping_item_rate_ids($order_shipping_items);
			} else {
				$canonical_rate_ids = $this->get_canonical_package_rate_ids($chosen_shipping_methods_session);
			}

			if (!count($this->get_matching_rates($canonical_rate_ids))) {
				return false;
			}
		}

		return parent::is_available();
	}



	/**
	 * Checks to see whether or not the admin settings are being accessed by the current request.
	 *
	 * @return bool
	 */
	private function is_accessing_settings()
	{
		if (is_admin()) {
			// phpcs:disable WordPress.Security.NonceVerification
			if (!isset($_REQUEST['page']) || 'wc-settings' !== $_REQUEST['page']) {
				return false;
			}
			if (!isset($_REQUEST['tab']) || 'checkout' !== $_REQUEST['tab']) {
				return false;
			}
			if (!isset($_REQUEST['section']) || 'glin-plugin' !== $_REQUEST['section']) {
				return false;
			}
			// phpcs:enable WordPress.Security.NonceVerification

			return true;
		}

		return false;
	}



	/**
	 * Loads all of the shipping method options for the enable_for_methods field.
	 *
	 * @return array
	 */
	private function load_shipping_method_options()
	{
		// Since this is expensive, we only want to do it if we're actually on the settings page.
		if (!$this->is_accessing_settings()) {
			return array();
		}

		$data_store = WC_Data_Store::load('shipping-zone');
		$raw_zones  = $data_store->get_zones();

		foreach ($raw_zones as $raw_zone) {
			$zones[] = new WC_Shipping_Zone($raw_zone);
		}

		$zones[] = new WC_Shipping_Zone(0);

		$options = array();
		foreach (WC()->shipping()->load_shipping_methods() as $method) {

			$options[$method->get_method_title()] = array();

			// Translators: %1$s shipping method name.
			$options[$method->get_method_title()][$method->id] = sprintf(__('Any &quot;%1$s&quot; method', 'glin-plugin'), $method->get_method_title());

			foreach ($zones as $zone) {

				$shipping_method_instances = $zone->get_shipping_methods();

				foreach ($shipping_method_instances as $shipping_method_instance_id => $shipping_method_instance) {

					if ($shipping_method_instance->id !== $method->id) {
						continue;
					}

					$option_id = $shipping_method_instance->get_rate_id();

					// Translators: %1$s shipping method title, %2$s shipping method id.
					$option_instance_title = sprintf(__('%1$s (#%2$s)', 'glin-plugin'), $shipping_method_instance->get_title(), $shipping_method_instance_id);

					// Translators: %1$s zone name, %2$s shipping method instance name.
					$option_title = sprintf(__('%1$s &ndash; %2$s', 'glin-plugin'), $zone->get_id() ? $zone->get_zone_name() : __('Other locations', 'glin-plugin'), $option_instance_title);

					$options[$method->get_method_title()][$option_id] = $option_title;
				}
			}
		}

		return $options;
	}



	/**
	 * Converts the chosen rate IDs generated by Shipping Methods to a canonical 'method_id:instance_id' format.
	 *
	 * @since  3.4.0
	 *
	 * @param  array $order_shipping_items  Array of WC_Order_Item_Shipping objects.
	 * @return array $canonical_rate_ids    Rate IDs in a canonical format.
	 */
	private function get_canonical_order_shipping_item_rate_ids($order_shipping_items)
	{

		$canonical_rate_ids = array();

		foreach ($order_shipping_items as $order_shipping_item) {
			$canonical_rate_ids[] = $order_shipping_item->get_method_id() . ':' . $order_shipping_item->get_instance_id();
		}

		return $canonical_rate_ids;
	}



	/**
	 * Converts the chosen rate IDs generated by Shipping Methods to a canonical 'method_id:instance_id' format.
	 *
	 * @since  3.4.0
	 *
	 * @param  array $chosen_package_rate_ids Rate IDs as generated by shipping methods. Can be anything if a shipping method doesn't honor WC conventions.
	 * @return array $canonical_rate_ids  Rate IDs in a canonical format.
	 */
	private function get_canonical_package_rate_ids($chosen_package_rate_ids)
	{

		$shipping_packages  = WC()->shipping()->get_packages();
		$canonical_rate_ids = array();

		if (!empty($chosen_package_rate_ids) && is_array($chosen_package_rate_ids)) {
			foreach ($chosen_package_rate_ids as $package_key => $chosen_package_rate_id) {
				if (!empty($shipping_packages[$package_key]['rates'][$chosen_package_rate_id])) {
					$chosen_rate          = $shipping_packages[$package_key]['rates'][$chosen_package_rate_id];
					$canonical_rate_ids[] = $chosen_rate->get_method_id() . ':' . $chosen_rate->get_instance_id();
				}
			}
		}

		return $canonical_rate_ids;
	}



	/**
	 * Indicates whether a rate exists in an array of canonically-formatted rate IDs that activates this gateway.
	 *
	 * @since  3.4.0
	 *
	 * @param array $rate_ids Rate ids to check.
	 * @return boolean
	 */
	private function get_matching_rates($rate_ids)
	{
		// First, match entries in 'method_id:instance_id' format. Then, match entries in 'method_id' format by stripping off the instance ID from the candidates.
		return array_unique(array_merge(array_intersect($this->enable_for_methods, $rate_ids), array_intersect($this->enable_for_methods, array_unique(array_map('wc_get_string_before_colon', $rate_ids)))));
	}



	/**
	 * Process the payment and return the result.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment($order_id)
	{
        // Criando um objeto DateTime para o dia de hoje
        $hoje = new DateTime();

        // Adicionando 3 dias
        $hoje->add(new DateInterval('P3D'));

        // Formatando a data no formato desejado
        $expireDate = $hoje->format('Y-m-d\TH:i:s\Z');
        
        global $woocommerce;
        $currency = get_woocommerce_currency_symbol();

        $order = wc_get_order($order_id);
        $cart_total = $this->get_order_total(); 
        $total = number_format($cart_total, 2, '.', ',');

        $url = 'https://pay.glin.com.br/merchant-api/remittances/';

		$body_req = [
            'clientReferenceId' => $order_id,
            'amount' => $total,
            'currency' => 'USD',
            'expiresAt' => $expireDate,
            'successUrl' => "https://cdo.travel/checkout/pedido-recebido/?order-id-glin=".$order_id,
            'cancelUrl' => 'https://cdo.travel/carrinho-de-compras/'
		];

		$args = array(
			'method' => 'POST',
			'headers' => array(
					'Authorization' => 'Bearer '. $this->token,
					'Content-Type' => 'application/json',
					'Connection' => 'keep-alive',
					'Accept-Encoding' => 'gzip, deflate, br',
					'Accept' => 'application/json'
				),
			'body' => json_encode($body_req),
			'timeout' => 90
		);
        
        $response = wp_remote_post($url, $args);	


        if($response['response']['code'] != 200){
            wc_add_notice(__('Ocorreu um erro ao realizar o pagamento, tente de novo!', 'glin-plugin'),
                'error'
            );

			return ['result' => 'fail'];
        }

        if(!is_wp_error($response)){

			$data = array();
			$body = array();

            $body = wp_remote_retrieve_body($response);

            $data = json_decode($body, true);

            $order->update_meta_data('id_transacao', $data['id']);
            $order->update_meta_data('url', $data['checkoutUrl']);
			$order->update_meta_data('status', $data['status']);
            
            $order->save();

            //adicionando a chave pix como anotação do pedido
            $order->add_order_note($data['checkoutUrl']);
			$order->add_order_note($data['id']);

            // Remove cart.
            WC()->cart->empty_cart();

            // Return thankyou redirect.
            return array(
                'result'   => 'success',
                'redirect' => $data['checkoutUrl'],
            );
        }else{
            return ['result' => 'fail'];
        }
        
	}



	/**
	 * Add content to the WC emails.
	 *
	 * @param WC_Order $order Order object.
	 * @param bool     $sent_to_admin  Sent to admin.
	 * @param bool     $plain_text Email format: plain text or HTML.
	 */
	public function email_instructions($order, $sent_to_admin, $plain_text = false)
	{
		if ($this->instructions && !$sent_to_admin && $this->id === $order->get_payment_method()) {
			echo wp_kses_post(wpautop(wptexturize($this->instructions)) . PHP_EOL);
		}
	}

}