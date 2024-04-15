<?php

class WC_Gateway_Wia extends WC_Payment_Gateway {

    public function __construct() {

        error_log('Initializing WC_Gateway_Wia');
        global $wp_rewrite;
        error_log(print_r($wp_rewrite->rules, true));

        $this->id = 'wia';
        $this->icon = plugin_dir_url(__FILE__) . '../assets/img/logo.png';
        $this->has_fields = true;
        $this->method_title = 'Wia Gateway';
        $this->method_description = 'Pasarela de pagos Wia para WooCommerce';

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_wia_scripts'), 999);

        add_filter('query_vars', array($this, 'add_query_vars'), 0);
        add_action('parse_request', array($this, 'handle_webhook'), 10);
    }

    public function wia_add_rewrite_rule() {

        add_rewrite_rule('^wia-payment-webhook/?$', 'index.php?wia_payment_webhook=1', 'top');
        global $wp_rewrite;
        $wp_rewrite->flush_rules(false); 
        error_log('Rewrite rules added');
    }
    
    public function add_query_vars($vars) {
        $vars[] = 'wia_payment_webhook';
        return $vars;
    }
    
    public function handle_webhook($wp) {
        error_log('handle_webhook invoked');
        if (isset($wp->query_vars['wia_payment_webhook'])) {
            error_log('Webhook hit detected with query var.');
            
            // Capturar el cuerpo del request
            $payload = json_decode(file_get_contents('php://input'), true);
            if ($payload === null) {
                error_log('Error decoding JSON payload');
                http_response_code(400);
                echo 'Invalid payload';
                exit;
            }
            
            error_log('Payload: ' . print_r($payload, true));
            
            // Llamar al método para procesar el webhook
            $this->process_webhook($payload);
        } else {
            error_log('Webhook hit NOT detected, available query vars: ' . print_r($wp->query_vars, true));
        }
    }
      
    
    protected function process_webhook($payload) {

        $order_id = $payload['purchase_id'];
        $order = wc_get_order($order_id);
        if (!$order) {
            error_log('Order not found: ' . $order_id);  // Más información sobre error
            http_response_code(404);
            echo 'Order not found';
            exit;
        }
    
        if ($order->has_status(['completed', 'cancelled'])) {
            error_log('Order already completed or cancelled: ' . $order_id);
            http_response_code(200);
            echo 'No action needed';
            exit;
        }
        
        $status = $payload['status_id'];
        $received = $payload['received_usd'];
        
        switch ($status) {
            case '1':
                $order->add_order_note('El pago no se ha realizado en Wia.');
                break;
            case '2':
                $order->add_order_note('Pago recibido parcialmente, no se ha pagado la totalidad. $'. $received);
                break;
            case '3':
                $order->update_status('cancelled', 'Pago cancelado por el usuario o el banco.');
                break;
            case '4':
                $order->payment_complete();
                $order->add_order_note('Pago completado mediante Wia. $'. $received);
                break;
            default:
                http_response_code(400);
                echo "Status not recognized";
                exit;
        }
    
        $order->save();
        http_response_code(200);
        echo 'Webhook processed';
    }
    

    public function enqueue_wia_scripts() {
        wp_enqueue_script('wia-checkout-js', plugin_dir_url(__FILE__) . '../assets/js/wia-checkout.js', array('jquery'), '1.0', true);

        wp_localize_script('wia-checkout-js', 'wia_params', array(
            'checkoutType' => $this->get_option('checkout_type'),
            'redirectUrl' => ''
        ));
        if (is_checkout()) {
            wp_enqueue_script('wia-checkout-js');
        }
    }
    

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'       => 'Activar/Desactivar',
                'label'       => 'Activar Wia Gateway',
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no'
            ),
            'title' => array(
                'title'       => 'Título',
                'type'        => 'text',
                'description' => 'Esto controla el título que el usuario ve durante el checkout.',
                'default'     => 'Pagar con criptomonedas',
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => 'Descripción',
                'type'        => 'textarea',
                'description' => 'Esto controla la descripción que el usuario ve durante el checkout.',
                'default'     => 'Paga con USDT, BNB, ETH o muchas más criptos mediante Wia.',
            ),
            'api_key' => array(
                'title'       => 'API Key',
                'type'        => 'text'
            ),
            'checkout_type' => array(
                'title'       => 'Tipo de Checkout',
                'type'        => 'select',
                'description' => 'En estos momentos solo se encuentra disponible el Standard Checkout.',
                'default'     => 'standard',
                'desc_tip'    => true,
                'options'     => array(
                    /* 'onpage'   => 'Onpage Checkout', */
                    'standard' => 'Standard Checkout'
                )
            ),
        );
    }

    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        $items = array();
        $total_quantity = 0;

        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            $items[] = array(
                'item' => $product->get_name(),
                'qty' => $item->get_quantity(),
                'total' => $item->get_total()
            );
            $total_quantity += $item->get_total();
        }

        $body = array(
            'purchaseId' => strval($order_id),
            'items' => $items,
            'quantity' => strval($total_quantity)
        );

        $response = $this->create_wia_transaction($body);
        if (is_wp_error($response) || $response['response']['code'] != 200) {
            wc_add_notice('Error al procesar el pago: ' . $response->get_error_message(), 'error');
            return;
        }

        $transaction_id = json_decode($response['body'])->transaction;
        $checkout_url = $this->get_option('checkout_type') == 'onpage' ?
            "https://app.wiabank.com/payment/onpage?transaction=$transaction_id" :
            "https://app.wiabank.com/payment/standar?transaction=$transaction_id";

        return array(
            'result'   => 'success',
            'redirect' => $checkout_url
        );
    }

    private function create_wia_transaction($body) {
        $api_url = 'https://api.wiabank.com/v2/transaction/merchant';
        $request = new WP_Http();
        $response = $request->post($api_url, array(
            'body' => json_encode($body),
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-API-KEY' => $this->get_option('api_key')
            ),
            'timeout' => 45
        ));
        return $response;
    }
}
