<?php
/**
 * ePay.ph WooCommerce Plugin
 * 
 * @author ePay.ph<admin@epay.ph>
 * @version 2.3.0
 * @example For callback : http://shoppingcarturl/?wc-api=WC_Epayph_Gateway
 */

/**
 * Plugin Name: WooCommerce ePay.ph
 * Description: WooCommerce ePay.ph Plugin
 * Author: ePay.ph
 * Author URI: http:/epay.ph
 * Version: 1.0.0
 * License: MIT
 * For callback : http://shoppingcarturl/?wc-api=WC_Epayph_Gateway
 */

/**
 * If WooCommerce plugin is not available
 * 
 */
function wcepayph_woocommerce_fallback_notice() {
    $message = '<div class="error">';
    $message .= '<p>' . __( 'WooCommerce ePay.ph Gateway depends on the last version of <a href="http://wordpress.org/extend/plugins/woocommerce/">WooCommerce</a> to work!' , 'wcepayph' ) . '</p>';
    $message .= '</div>';
    echo $message;
}

//Load the function
add_action( 'plugins_loaded', 'wcepayph_gateway_load', 0 );

/**
 * Load epayph gateway plugin function
 * 
 * @return mixed
 */
function wcepayph_gateway_load() {
    if ( !class_exists( 'WC_Payment_Gateway' ) ) {
        add_action( 'admin_notices', 'wcepayph_woocommerce_fallback_notice' );
        return;
    }

    //Load language
    load_plugin_textdomain( 'wcepayph', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

    add_filter( 'woocommerce_payment_gateways', 'wcepayph_add_gateway' );

    /**
     * Add epayph gateway to ensure WooCommerce can load it
     * 
     * @param array $methods
     * @return array
     */
    function wcepayph_add_gateway( $methods ) {
        $methods[] = 'WC_Epayph_Gateway';
        return $methods;
    }

    /**
     * Define the ePay.ph gateway
     * 
     */
    class WC_Epayph_Gateway extends WC_Payment_Gateway {

		protected $notify_url;
        /**
         * Construct the epayph gateway class
         * 
         * @global mixed $woocommerce
         */
        public function __construct() {
            global $woocommerce;

            $this->id = 'epayph';
            $this->icon = plugins_url( 'images/epayph.png', __FILE__ );
            $this->has_fields = false;
            $this->pay_url = 'https://epay.ph/checkout/api/?';
            $this->method_title = __( 'epayph', 'wcepayph' );
			$this->notify_url = WC()->api_request_url( 'WC_Epayph_Gateway' );

            $this->init_form_fields();
            $this->init_settings();

            // Define user setting variables.
            $this->title = $this->settings['title'];
            $this->description = $this->settings['description'];
            $this->merchant_id = $this->settings['merchant_id'];
            
            // Actions.
            //add_action( 'valid_epayph_request_returnurl', array( &$this, 'check_epayph_response_returnurl' ) );
            add_action( 'woocommerce_receipt_epayph', array( &$this, 'receipt_page' ) );
			add_action( 'woocommerce_api_wc_epayph_gateway', array( $this, 'check_epayph_response_returnurl' ) );
			
            //save setting configuration
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
						

            // Checking if merchant_id is not empty.
            $this->merchant_id == '' ? add_action( 'admin_notices', array( &$this, 'merchant_id_missing_message' ) ) : '';

        }

        /**
         * Checking if this gateway is enabled and available in the user's country.
         *
         * @return bool
         */
        public function is_valid_for_use() {
            if ( !in_array( get_woocommerce_currency() , array( 'PHP','USD','SGD' ) ) ) {
                return false;
            }
            return true;
        }

        /**
         * Admin Panel Options
         * - Options for bits like 'title' and availability on a country-by-country basis.
         *
         */
        public function admin_options() {
            ?>
            <h3><?php _e( 'ePay.ph Online Payment', 'wcepayph' ); ?></h3>
            <p><?php _e( 'ePay.ph Online Payment works by sending the user to ePay.ph to enter their payment information.', 'wcepayph' ); ?></p>
            <table class="form-table">
                <?php $this->generate_settings_html(); ?>
            </table><!--/.form-table-->
            <?php
        }

        /**
         * Gateway Settings Form Fields.
         * 
         */
        public function init_form_fields() {

            $this->form_fields = array(
                'enabled' => array(
                    'title' => __( 'Enable/Disable', 'wcepayph' ),
                    'type' => 'checkbox',
                    'label' => __( 'Enable ePay.ph', 'wcepayph' ),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => __( 'Title', 'wcepayph' ),
                    'type' => 'text',
                    'description' => __( 'This controls the title which the user sees during checkout.', 'wcepayph' ),
                    'default' => __( 'ePay.ph Online Payment (Credit/Debit Card,Bank Deposit)', 'wcepayph' )
                ),
                'description' => array(
                    'title' => __( 'Description', 'wcepayph' ),
                    'type' => 'textarea',
                    'description' => __( 'This controls the description which the user sees during checkout.', 'wcepayph' ),
                    'default' => __( 'Pay with ePay.ph Online Payment', 'wcepayph' )
                ),
                'merchant_id' => array(
                    'title' => __( 'Merchant ID', 'wcepayph' ),
                    'type' => 'text',
                    'description' => __( 'Please enter your ePay.ph Merchant ID.', 'wcepayph' ) . ' ' . sprintf( __( 'You can to get this information in: %sePay.ph Account%s.', 'wcepayph' ), '<a href="https://epay.ph/" target="_blank">', '</a>' ),
                    'default' => ''
                )
            );
        }

        /**
         * Generate the form.
         *
         * @param mixed $order_id
         * @return string
         */
        public function generate_form( $order_id ) {
            $order = new WC_Order( $order_id );	
            if ( sizeof( $order->get_items() ) > 0 ) 
                foreach ( $order->get_items() as $item )
                    if ( $item['qty'] )
                        $item_names[] = $item['name'] . ' x ' . $item['qty'];

            $desc = sprintf( __( 'Order %s' , 'woocommerce'), $order->get_order_number() ) . " - " . implode( ', ', $item_names );

            $epayph_args = array(
				'cmd'           => '_cart',
				'business'      => $this->merchant_id,
				'no_note'       => 1,
				'currency_code' => get_woocommerce_currency(),
				'charset'       => 'utf-8',
				'rm'            => is_ssl() ? 2 : 1,
				'upload'        => 1,
				'return'        => esc_url( add_query_arg( 'utm_nooverride', '1', $this->get_return_url( $order ) ) ),
				'cancel_return' => esc_url( $order->get_cancel_order_url() ),
				'bn'            => 'WooCommerce_Cart',
				'invoice'       => $order->id,
				'custom'        => serialize( array( $order->id, $order->order_key ) ),
				'notify_url'    => $this->notify_url,
				'first_name'    => $order->billing_first_name,
				'last_name'     => $order->billing_last_name,
				'company'       => $order->billing_company,
				'address1'      => $order->billing_address_1,
				'address2'      => $order->billing_address_2,
				'city'          => $order->billing_city,
				'state'         => $this->get_paypal_state( $order->billing_country, $order->billing_state ),
				'zip'           => $order->billing_postcode,
				'country'       => $order->billing_country,
				'email'         => $order->billing_email,
				'phone'			=> $order->billing_phone,
				'affiliate'		=> @$_COOKIE['affiliate'], 
				'location'		=> $order->billing_city.', '.$this->get_paypal_state( $order->billing_country, $order->billing_state ).', '.$order->billing_country
            );

            $epayph_args_array = array();
            foreach ($epayph_args as $key => $value) {
                $epayph_args_array[] = "<input type='hidden' name='".$key."' value='". $value ."' />";
            }		
			foreach ($this->get_phone_number_args( $order ) as $key => $value) {
                $epayph_args_array[] = "<input type='hidden' name='".$key."' value='". $value ."' />";				
			}
			foreach ($this->get_shipping_args( $order ) as $key => $value) {
                $epayph_args_array[] = "<input type='hidden' name='".$key."' value='". $value ."' />";				
			}
			foreach ($this->get_line_item_args( $order ) as $key => $value) {
                $epayph_args_array[] = "<input type='hidden' name='".$key."' value='". $value ."' />";				
			}
			
			
            return "<form action='".$this->pay_url."' method='post' id='epayph_payment_form' name='epayph_payment_form'>"
                    . implode('', $epayph_args_array)
                    . "<input type='submit' class='button-alt' id='submit_epayph_payment_form' value='" . __('Pay via ePay.ph', 'woothemes') . "' /> "
                    . "<a class='button cancel' href='" . $order->get_cancel_order_url() . "'>".__('Cancel order &amp; restore cart', 'woothemes')."</a>"
                    . "<script>document.epayph_payment_form.submit();</script>"
                    . "</form>";
        }
		
        /**
         * Order error button.
         *
         * @param  object $order Order data.
         * @return string Error message and cancel button.
         */
        protected function epayph_order_error( $order ) {
            $html = '<p>' . __( 'An error has occurred while processing your payment, please try again. Or contact us for assistance.', 'wcepayph' ) . '</p>';
            $html .='<a class="button cancel" href="' . esc_url( $order->get_cancel_order_url() ) . '">' . __( 'Click to try again', 'wcepayph' ) . '</a>';
            return $html;
        }

        /**
         * Process the payment and return the result.
         *
         * @param int $order_id
         * @return array
         */
        public function process_payment( $order_id ) {
       		$order = new WC_Order( $order_id );
            return array(
                'result' => 'success',
				'redirect' => add_query_arg( 'order', $order->id, add_query_arg( 'key', $order->order_key, get_permalink( woocommerce_get_page_id( 'pay' ) ) ) )
            );
        }

        /**
         * Output for the order received page.
         * 
         */
        public function receipt_page( $order ) {
            echo $this->generate_form( $order );
        }

        /**
         * Check for ePay.ph Response
         *
         * @access public
         * @return void
         */
       
		
        /**
         * This part is returnurl function for epayph
         * 
         * @global mixed $woocommerce
         */
        function check_epayph_response_returnurl($posted) {
            global $woocommerce;
			
			if ( ! empty( $_POST ) && $this->validate_ipn() ) {			
				$order = new WC_Order( $_POST['invoice'] );
			   
				switch($_REQUEST['payment_status']){
					case 'Completed':
						$order->add_order_note('ePay.ph Payment Status: SUCCESSFUL'.'<br>Transaction ID: ' . $tranID . $referer);								
						$order->payment_complete();
						wp_redirect($order->get_checkout_order_received_url());
						break;
					case 'Pending':
						if ( $order->has_status( 'completed' ) ) {
							exit;
						}else{					
							$order->add_order_note('ePay.ph Payment Status: PENDING');
							$order->update_status('pending', __('Awaiting Payment Approval', 'woocommerce'));
							//wp_redirect($order->get_checkout_order_received_url());
						};
						break;
					case 'Cancelled':
						$order->add_order_note('ePay.ph Payment Status: FAILED');
						$order->update_status('failed', __('Payment Failed', 'woocommerce'));
						//wp_redirect($order->get_cancel_order_url());
						break;
					case 'Refunded':
						$order->add_order_note('ePay.ph Payment Status: Refunded');
						$order->update_status('refunded', __('Payment Refunded', 'woocommerce'));
						//wp_redirect($order->get_cancel_order_url());			
						break;
					case 'Display':
						break;
					default:	
						$order->add_order_note('ePay.ph Payment Status: Invalid Transaction');
						$order->update_status('on-hold', __('Invalid Transaction', 'woocommerce'));
						//wp_redirect($order->get_cancel_order_url());			
				};
				exit;
				
			};

        }
		
	
        /**
         * Adds error message when not configured the app_key.
         * 
         */
        public function merchant_id_missing_message() {
            $message = '<div class="error">';
            $message .= '<p>' . sprintf( __( '<strong>Gateway Disabled</strong> You should inform your Merchant ID in ePay.ph. %sClick here to configure!%s' , 'wcepayph' ), '<a href="' . get_admin_url() . 'admin.php?page=woocommerce_settings&tab=payment_gateways&section=WC_Epayph_Gateway">', '</a>' ) . '</p>';
            $message .= '</div>';
            echo $message;
        }
	
		/**
		 * Get phone number args for paypal request
		 * @param  WC_Order $order
		 * @return array
		 */
		protected function get_phone_number_args( $order ) {
			if ( in_array( $order->billing_country, array( 'US','CA' ) ) ) {
				$phone_number = str_replace( array( '(', '-', ' ', ')', '.' ), '', $order->billing_phone );
				$phone_args   = array(
					'night_phone_a' => substr( $phone_number, 0, 3 ),
					'night_phone_b' => substr( $phone_number, 3, 3 ),
					'night_phone_c' => substr( $phone_number, 6, 4 ),
					'day_phone_a' 	=> substr( $phone_number, 0, 3 ),
					'day_phone_b' 	=> substr( $phone_number, 3, 3 ),
					'day_phone_c' 	=> substr( $phone_number, 6, 4 )
				);
			} else {
				$phone_args = array(
					'night_phone_b' => $order->billing_phone,
					'day_phone_b' 	=> $order->billing_phone
				);
			}
			return $phone_args;
		}
	
		/**
		 * Get shipping args for paypal request
		 * @param  WC_Order $order
		 * @return array
		 */
		protected function get_shipping_args( $order ) {
			$shipping_args = array();
	
			if ( 'yes' == $this->get_option( 'send_shipping' ) ) {
				$shipping_args['address_override'] = $this->get_option( 'address_override' ) === 'yes' ? 1 : 0;
				$shipping_args['no_shipping']      = 0;
	
				// If we are sending shipping, send shipping address instead of billing
				$shipping_args['first_name']       = $order->shipping_first_name;
				$shipping_args['last_name']        = $order->shipping_last_name;
				$shipping_args['company']          = $order->shipping_company;
				$shipping_args['address1']         = $order->shipping_address_1;
				$shipping_args['address2']         = $order->shipping_address_2;
				$shipping_args['city']             = $order->shipping_city;
				$shipping_args['state']            = $this->get_paypal_state( $order->shipping_country, $order->shipping_state );
				$shipping_args['country']          = $order->shipping_country;
				$shipping_args['zip']              = $order->shipping_postcode;
			} else {
				$shipping_args['no_shipping']      = 1;
			}
	
			return $shipping_args;
		}
	
		/**
		 * Get line item args for paypal request
		 * @param  WC_Order $order
		 * @return array
		 */
		protected function get_line_item_args( $order ) {
			/**
			 * Try passing a line item per product if supported
			 */
			if ( ( ! wc_tax_enabled() || ! wc_prices_include_tax() ) && $this->prepare_line_items( $order ) ) {
	
				$line_item_args             = $this->get_line_items();
				$line_item_args['tax_cart'] = $order->get_total_tax();
	
				if ( $order->get_total_discount() > 0 ) {
					$line_item_args['discount_amount_cart'] = round( $order->get_total_discount(), 2 );
				}
	
			/**
			 * Send order as a single item
			 *
			 * For shipping, we longer use shipping_1 because paypal ignores it if *any* shipping rules are within paypal, and paypal ignores anything over 5 digits (999.99 is the max)
			 */
			} else {
	
				$this->delete_line_items();
	
				$this->add_line_item( $this->get_order_item_names( $order ), 1, number_format( $order->get_total() - round( $order->get_total_shipping() + $order->get_shipping_tax(), 2 ), 2, '.', '' ), $order->get_order_number() );
				$this->add_line_item( sprintf( __( 'Shipping via %s', 'woocommerce' ), ucwords( $order->get_shipping_method() ) ), 1, number_format( $order->get_total_shipping() + $order->get_shipping_tax(), 2, '.', '' ) );
	
				$line_item_args = $this->get_line_items();
			}
	
			return $line_item_args;
		}
	
		/**
		 * Get order item names as a string
		 * @param  WC_Order $order
		 * @return string
		 */
		protected function get_order_item_names( $order ) {
			$item_names = array();
	
			foreach ( $order->get_items() as $item ) {
				$item_names[] = $item['name'] . ' x ' . $item['qty'];
			}
	
			return implode( ', ', $item_names );
		}
	
		/**
		 * Get order item names as a string
		 * @param  WC_Order $order
		 * @param  array $item
		 * @return string
		 */
		protected function get_order_item_name( $order, $item ) {
			$item_name = $item['name'];
			$item_meta = new WC_Order_Item_Meta( $item['item_meta'] );
	
			if ( $meta = $item_meta->display( true, true ) ) {
				$item_name .= ' ( ' . $meta . ' )';
			}
	
			return $item_name;
		}
	
		/**
		 * Return all line items
		 */
		protected function get_line_items() {
			return $this->line_items;
		}
	
		/**
		 * Remove all line items
		 */
		protected function delete_line_items() {
			$this->line_items = array();
		}
	
		/**
		 * Get line items to send to paypal
		 *
		 * @param  WC_Order $order
		 * @return bool
		 */
		protected function prepare_line_items( $order ) {
			$this->delete_line_items();
			$calculated_total = 0;
	
			// Products
			foreach ( $order->get_items( array( 'line_item', 'fee' ) ) as $item ) {
				if ( 'fee' === $item['type'] ) {
					$line_item        = $this->add_line_item( $item['name'], 1, $item['line_total'] );
					$calculated_total += $item['line_total'];
				} else {
					$product          = $order->get_product_from_item( $item );
					$line_item        = $this->add_line_item( $this->get_order_item_name( $order, $item ), $item['qty'], $order->get_item_subtotal( $item, false ), $product->get_sku() );
					$calculated_total += $order->get_item_subtotal( $item, false ) * $item['qty'];
				}
	
				if ( ! $line_item ) {
					return false;
				}
			}
	
			// Shipping Cost item - paypal only allows shipping per item, we want to send shipping for the order
			if ( $order->get_total_shipping() > 0 && ! $this->add_line_item( sprintf( __( 'Shipping via %s', 'woocommerce' ), $order->get_shipping_method() ), 1, round( $order->get_total_shipping(), 2 ) ) ) {
				return false;
			}
	
			// Check for mismatched totals
			if ( ( $calculated_total + $order->get_total_tax() + round( $order->get_total_shipping(), 2 ) - round( $order->get_total_discount(), 2 ) ) != $order->get_total() ) {
				return false;
			}
	
			return true;
		}
	
		/**
		 * Add PayPal Line Item
		 * @param string  $item_name
		 * @param integer $quantity
		 * @param integer $amount
		 * @param string  $item_number
		 * @return bool successfully added or not
		 */
		protected function add_line_item( $item_name, $quantity = 1, $amount = 0, $item_number = '' ) {
			$index = ( sizeof( $this->line_items ) / 4 ) + 1;
	
			if ( ! $item_name || $amount < 0 || $index > 9 ) {
				return false;
			}
	
			$this->line_items[ 'item_name_' . $index ]   = html_entity_decode( wc_trim_string( $item_name, 127 ), ENT_NOQUOTES, 'UTF-8' );
			$this->line_items[ 'quantity_' . $index ]    = $quantity;
			$this->line_items[ 'amount_' . $index ]      = $amount;
			$this->line_items[ 'item_number_' . $index ] = $item_number;
	
			return true;
		}

		protected function get_paypal_state( $cc, $state ) {
			if ( 'US' === $cc ) {
				return $state;
			}
	
			$states = WC()->countries->get_states( $cc );
	
			if ( isset( $states[ $state ] ) ) {
				return $states[ $state ];
			}
	
			return $state;
		}


		/**
		 * Check ePay.ph IPN validity
		 */
		public function validate_ipn() {
			// Get received values from post data
			$validate_ipn = array( 'cmd' => '_notify-validate' );
			$validate_ipn += wp_unslash( $_POST );
	
			// Send back post vars to paypal
			$params = array(
				'body'        => $validate_ipn,
				'sslverify'   => false,
				'timeout'     => 60,
				'httpversion' => '1.1',
				'compress'    => false,
				'decompress'  => false,
				'user-agent'  => 'WooCommerce/' . WC()->version
			);
	
			// Post back to get a response
			$response = wp_remote_post( 'https://epay.ph/api/validateIPN', $params );
			//mail('pjabadesco@gmail.com','apopay response',serialize($response));
	
			// check to see if the request was valid
			if ( ! is_wp_error( $response ) && $response['response']['code'] >= 200 && $response['response']['code'] < 300 && $response['body']=='{"return":"VERIFIED"}') { 
				return true;
			}

			return false;
		}
		
    }
}