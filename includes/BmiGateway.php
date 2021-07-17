<?php

namespace siaeb\edd\gateways\bmi\includes;

use EDD_Payment;

class BmiGateway {

	protected $data = [
		'bankKey'       => 'sadad_gate',
		'adminLabel'    => 'بانک ملی',
		'checkoutLabel' => 'بانک ملی',
		'priority'      => 11,
	];

	public function __construct() {
		add_filter( 'edd_payment_gateways', [ $this, 'registerGateway' ], $this->data['priority'] );
		add_filter( 'edd_settings_gateways', [ $this, 'registerSettings' ], $this->data['priority'] );
		add_filter( 'edd_' . $this->data['bankKey'] . '_cc_form', [ $this, 'ccForm' ], $this->data['priority'] );
		add_action( 'edd_gateway_' . $this->data['bankKey'], [ $this, 'processPayment' ], $this->data['priority'] );
		add_action( 'init', [ $this, 'verifyPayment' ] );
		add_action( 'edd_payment_receipt_after', [ $this, 'afterPaymentReceipt' ], 10, 2 );
	}

	/**
	 * Register gateway
	 *
	 * @param array $gateways
	 *
	 * @return mixed
	 */
	public function registerGateway( $gateways ) {
		$gateways[ $this->data['bankKey'] ] = [
			'admin_label'    => $this->data['adminLabel'],
			'checkout_label' => $this->data['checkoutLabel']
		];

		return $gateways;
	}

	function registerSettings( $settings ) {

		$page_op = edd_get_option( 'edd_custom_pay_res_page', false );
		$post    = get_post( $page_op );

		$Melli_settings = array(
			array(
				'id'   => 'bmi_new_setting',
				'name' => '<strong>بانک ملی</strong>',
				'desc' => 'پيکربندي درگاه بانک ملی ایران با تنظيمات فروشگاه',
				'type' => 'header'
			),
			array(
				'id'   => 'bmi_new_merchant',
				'name' => 'شماره پذیرنده',
				'desc' => 'این شماره توسط شرکت داده‌ورزی سداد برای شما ایمیل شده است',
				'type' => 'text',
				'size' => 'medium'
			),
			array(
				'id'   => 'bmi_new_terminal',
				'name' => 'شماره ترمینال',
				'desc' => 'این شماره توسط شرکت داده‌ورزی سداد برای شما ایمیل شده است',
				'type' => 'text',
				'size' => 'medium'
			),
			array(
				'id'   => 'bmi_new_TerminalKey',
				'name' => 'کلید تراکنش',
				'desc' => 'کلید تراکنش توسط شرکت داده‌ورزی سداد برای شما ایمیل شده است',
				'type' => 'password',
				'size' => 'medium'
			),

		);

		return array_merge( $settings, $Melli_settings );
	}

	/**
	 * Process payment
	 *
	 * @since 1.0
	 */
	function processPayment( $purchaseData ) {
		$merchantId = edd_get_option( 'bmi_new_merchant', '' );
		$terminal   = edd_get_option( 'bmi_new_terminal', '' );
		$userkey    = edd_get_option( 'bmi_new_TerminalKey', '' );
		$currency   = edd_get_currency();

		$payment_data = [
			'price'        => $purchaseData['price'],
			'date'         => $purchaseData['date'],
			'user_email'   => $purchaseData['post_data']['edd_email'],
			'purchase_key' => $purchaseData['purchase_key'],
			'currency'     => $currency,
			'downloads'    => $purchaseData['downloads'],
			'cart_details' => $purchaseData['cart_details'],
			'user_info'    => $purchaseData['user_info'],
			'status'       => 'pending'
		];
		$payment_id   = edd_insert_payment( $payment_data );

		if ( $payment_id ) {
			$new_payment = new EDD_Payment( $payment_id );
			$orderId     = time();

			$site_url = trailingslashit( get_site_url() );

			$callBackUrl = add_query_arg( [
				'edd-listener' => $this->data['bankKey'],
				'sd_action'    => 'check',
				'orderId'      => $orderId,
				'pid'          => $payment_id
			], $site_url );

			$Amount = $purchaseData['price'];

			if ( strtolower( $currency ) == 'irt' ) {
				$Amount = $Amount * 10;
			}

			$date_time = date( "Y-m-d\TH:i:s" );
			$SignData  = sprintf( '%s;%s;%s', $terminal, $orderId, $Amount );

			$payment_page = "https://sadad.shaparak.ir/VPG/Purchase";
			$url          = 'https://sadad.shaparak.ir/VPG/api/v0/Request/PaymentRequest';

			$AdditionalData = json_encode( array( 'oid' => $orderId, 'Amount' => $Amount ) );
			$post           = [
				'MerchantId'     => $merchantId,
				'TerminalId'     => $terminal,
				'Amount'         => $Amount,
				'OrderId'        => $orderId,
				'LocalDateTime'  => $date_time,
				'ReturnUrl'      => $callBackUrl,
				'SignData'       => $this->encryptTripleDESOpenSSL( $SignData, base64_decode( $userkey ) ),
				'AdditionalData' => $AdditionalData
			];
			$data_string    = json_encode( $post );

			/////////////////PAY REQUEST PART/////////////////////////
			$Result = $this->postData( $url, $data_string );

			$PayResult_JSON = $Result['res'];
			$post_err       = $Result['err'];

			if ( isset( $PayResult_JSON->ResCode ) && intval( $PayResult_JSON->ResCode ) == 0 ) {
				// Successfull Pay Request
				$new_payment->update_meta( 'sadad_token', $PayResult_JSON->Token );
				$new_payment->update_meta( 'bmi_orderId', $orderId );

				$payment_page_url = add_query_arg( 'Token', $PayResult_JSON->Token, $payment_page );
				$this->goToURL( $payment_page_url );
			} else {
				$fault = - 2;
				if ( $PayResult_JSON == false ) {
					$err = $post_err;
					// @TODO: We can log error message
				} else {
					if ( isset( $PayResult_JSON->ResCode ) ) {
						$fault = $PayResult_JSON->ResCode;
						$err   = $this->getRequestErrorMessage( $fault );
						if ( $err == "" && isset( $PayResult_JSON->Description ) ) {
							$err = $PayResult_JSON->Description;
						}
					} else {
						$fault = - 2;
						// @TODO: We can log error message
						$err = $this->getRequestErrorMessage( $fault );
					}
				}
				edd_update_payment_status( $payment_id, 'failed' );
				edd_insert_payment_note( $payment_id, $err );
				edd_set_error( $fault, $err );
				edd_send_back_to_checkout( '?payment-mode=' . $purchaseData['post_data']['edd-gateway'] );
			}

			///************END of PAY REQUEST***************///
		} else {
			edd_set_error( 'P01', 'خطا در ایجاد پرداخت، لطفاً مجدداً تلاش کنید...' );
			edd_send_back_to_checkout( '?payment-mode=' . $purchaseData['post_data']['edd-gateway'] );
		}
	}

	/**
	 * Verify payment
	 *
	 * @since 1.0
	 */
	function verifyPayment() {

		$gate       = filter_input( INPUT_GET, 'edd-listener' );
		$sd_action  = filter_input( INPUT_GET, 'sd_action' );
		$payment_id = filter_input( INPUT_GET, 'pid' );
		$res_id     = filter_input( INPUT_GET, 'orderId' );

		if ( $gate == $this->data['bankKey'] && $sd_action = 'check' && $payment_id ) {

			$userkey = edd_get_option( 'bmi_new_TerminalKey', '' );

			$edd_payment = edd_get_payment( $payment_id );
			if ( ! $edd_payment ) {
				wp_die( "شماره سفارش یافت نشد!", "خطا" );
				exit();
			}
			/////////////////VERIFY REQUEST///////////////////////
			if ( ! edd_is_test_mode() ) {
				// Call the SOAP method
				if ( $edd_payment->status != 'complete' && $edd_payment->status != 'publish' ) {

					// $price = edd_currency_filter(edd_format_amount($edd_payment->total));
					$ResCode = filter_input( INPUT_GET, 'ResCode' );
					if ( $ResCode == 0 ) {
						$token_key = $edd_payment->get_meta( 'sadad_token' );
						if ( empty( $token_key ) ) {
							$token_key = filter_input( INPUT_GET, 'Token', FILTER_SANITIZE_STRING );
						}
						$post = [
							'Token'    => $token_key,
							'SignData' => $this->encryptTripleDESOpenSSL( $token_key, base64_decode( $userkey ) )
						];

						$data_string = json_encode( $post );

						$url = 'https://sadad.shaparak.ir/VPG/api/v0/Advice/Verify';

						$Result         = $this->postData( $url, $data_string );
						$VerifyResult_J = $Result['res'];
						$post_err       = $Result['err'];


						if ( isset( $VerifyResult_J->ResCode ) && intval( $VerifyResult_J->ResCode ) == 0 ) {
							$RefNo      = $VerifyResult_J->RetrivalRefNo;
							$TraceNo    = $VerifyResult_J->SystemTraceNo;
							$do_publish = true;
						} else {
							$do_publish = false;
						}

						$transaction_id = isset( $VerifyResult_J->SystemTraceNo ) ? $VerifyResult_J->SystemTraceNo : 0;
						$fault          = isset( $VerifyResult_J->ResCode ) ? $VerifyResult_J->ResCode : - 2;

						if ( $do_publish == true ) {
							$fault                = $VerifyResult_J->ResCode;
							$err_msg              = $this->getVerifyErrorMessage( $fault );
							$RefNo                = $VerifyResult_J->RetrivalRefNo;
							$AppStatusDescription = $VerifyResult_J->Description;
							$TraceNo              = $VerifyResult_J->SystemTraceNo;
							$do_publish           = false;
							edd_update_payment_status( $payment_id, 'publish' );
							edd_insert_payment_note( $payment_id, 'شماره تراکنش:' . $TraceNo );
							$edd_payment->update_meta( 'bmi_new_TraceNo', $TraceNo );
							edd_set_payment_transaction_id( $payment_id, $TraceNo );

							edd_send_to_success_page();
							edd_empty_cart();

						} else {
							$err = "";
							if ( $VerifyResult_J == false ) {
								// We can log error messages
							} else {
								if ( is_array( $VerifyResult_J ) ) {
									$fault = $VerifyResult_J->ResCode;
								} else {
									$fault = - 2;
									// We can log error messages
								}
								$err = $this->getVerifyErrorMessage( $fault );
							}

							edd_set_error( $fault, 'تراکنش ناموفق بود.<br/>اگر وجهی از حساب شما کسر شده باشد، تا پایان روز جاری به حساب شما باز خواهد گشت.' );
							edd_update_payment_status( $payment_id, 'failed' );
							edd_insert_payment_note( $payment_id, $err );
							edd_send_back_to_checkout( '?payment-mode=' . $this->data['bankKey'] );
						}
					} else {
						edd_set_error( '-1', 'تراکنش ناموفق بود.<br/>اگر وجهی از حساب شما کسر شده باشد، تا پایان روز جاری به حساب شما باز خواهد گشت.' );
						edd_update_payment_status( $payment_id, 'failed' );
						edd_insert_payment_note( '-1', 'تراکنش ناموفق بود' );
						edd_send_back_to_checkout( '?payment-mode=' . $this->data['bankKey'] );
					}
				} else {
					if ( $edd_payment->status == 'complete' || $edd_payment->status == 'publish' ) {
						edd_send_to_success_page();
					}
				}
			}
		}
	}

	/**
	 *
	 */
	function ccForm() {
		do_action( 'bmi_new_cc_form_action' );
	}

	function afterPaymentReceipt( $payment, $edd_receipt_args ) {
		$bmi_new_TraceNo = edd_get_payment_meta( $payment->ID, 'bmi_new_TraceNo', true );

		if ( $bmi_new_TraceNo && ! empty( $bmi_new_TraceNo ) ) {
			?>
			<tr>
				<td><strong>شماره تراکنش</strong></td>
				<td><?php echo $bmi_new_TraceNo; ?></td>
			</tr>
			<?php
		}
	}

	/**
	 * encrypt tripledes
	 *
	 * @param type $data
	 * @param type $secret
	 *
	 * @return string encrypted data
	 */
	private function encryptTripleDESOpenSSL( $data, $secret ) {
		$method    = 'DES-EDE3';
		$iv        = openssl_random_pseudo_bytes( openssl_cipher_iv_length( $method ) );
		$encrypted = openssl_encrypt( $data, $method, $secret, 0, $iv );

		return $encrypted;
	}

	/**
	 *
	 * @param string $url URL to post data
	 * @param string $data_string encoded data as JSON
	 *
	 * @return array 'err' => as string , 'res' => Result as JSON
	 */
	private function postData( $url, $data_string ) {
		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_0 );
		curl_setopt( $ch, CURLOPT_FORBID_REUSE, 1 );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_VERBOSE, 0 );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 0 );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, 0 );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $data_string );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [
			'Content-Type: application/json',
			'Content-Length: ' . strlen( $data_string )
		] );

		$Result   = curl_exec( $ch );
		$post_err = curl_error( $ch );
		if ( $Result == false ) {
			$Result_JSON = $Result;
		} else {
			$Result_JSON = json_decode( $Result );
		}

		curl_close( $ch );
		$res = array( 'err' => $post_err, 'res' => $Result_JSON );

		return $res;
	}

	//Go TO URL
	private function goToURL( $url ) {
		die( '<script type="text/javascript">
                    location = "' . $url . '";
                    </script>' );
	}

	private function getRequestErrorMessage( $ErrorCode ) {
		$ErrorDesc = "";
		switch ( $ErrorCode ) {
			case "-2":
				$ErrorDesc = "خطای نا مشخص: این خطا به دلیل عدم پاسخگویی از سوی وب سرویس شرکت سداد به وجود می آید؛ برای اطلاع از دلیل این خطا با کارشناسان شرکت داده ورزی سداد تماس حاصل نمایید.
                        <a target='blank' href='" . get_site_url() . "/bmi_error.html'>لینک نتیجه دریافتی از بانک</a>";
				break;
			case "0":
				$ErrorDesc = "تراکنش موفق";
				break;
			case "3":
				$ErrorDesc = "nvalid merchant (پذيرنده کارت فعال نیست لطفا با بخش امور پذيرندگان، تماس حاصل فرمائید)";
				break;
			case "23":
				$ErrorDesc = "Merchant Inactive (پذيرنده کارت نامعتبر است لطفا با بخش امور پذيرندگان، تماس حاصل فرمائید)";
				break;
			case "1000":
				$ErrorDesc = "ترتیب پارامترهای ارسالی اشتباه می باشد، لطفا مسئول فنی پذيرنده با بانک تماس حاصل فرمايند ";
				break;
			case "1001":
				$ErrorDesc = "لطفا مسئول فنی پذيرنده با بانک تماس حاصل فرمايند،پارامترهای پرداخت اشتباه می باشد";
				break;
			case "1002":
				$ErrorDesc = "خطا در سیستم- تراکنش ناموفق";
				break;
			case "1003":
				$ErrorDesc = "IP پذيرنده اشتباه است.لطفا مسئول فنی پذيرنده با بانک تماس حاصل فرمايند";
				break;
			case "1004":
				$ErrorDesc = "لطفا مسئول فنی پذيرنده با بانک تماس حاصل فرمايند،شماره پذيرنده اشتباه است";
				break;
			case "1005":
				$ErrorDesc = "خطای دسترسی:لطفا بعدا تلاش فرمايید";
				break;
			case "1006":
				$ErrorDesc = "خطا در سیستم";
				break;
			case "1011":
				$ErrorDesc = "درخواست تکراری- شماره سفارش تکراری می باشد";
				break;
			case "1012":
				$ErrorDesc = "اطلاعات پذيرنده صحیح نیست، يکی از موارد تاريخ،زمان يا کلید تراکنش اشتباه است. لطفا مسئول فنی پذيرنده با بانک تماس حاصل فرمايند.";
				break;
			case "1015":
				$ErrorDesc = "پاسخ خطای نامشخص از سمت مرکز";
				break;
			case "1017":
				$ErrorDesc = "مبلغ درخواستی شما جهت پرداخت از حد مجاز تعريف شده برای اين پذيرنده بیشتر است.";
				break;
			case "1018":
				$ErrorDesc = "اشکال در تاريخ و زمان سیستم. لطفا تاريخ و زمان سرور خود را با بانک هماهنگ نمايید. ";
				break;
			case "1019":
				$ErrorDesc = "امکان پرداخت از طريق سیستم شتاب برای اين پذيرنده امکان پذير نیست";
				break;
			case "1020":
				$ErrorDesc = "پذيرنده غیرفعال شده است.لطفا جهت فعال سازی با بانک تماس بگیريد";
				break;
			case "1023":
				$ErrorDesc = "آدرس بازگشت پذيرنده نامعتبر است";
				break;
			case "1024":
				$ErrorDesc = "مهر زمانی پذيرنده نامعتبر است";
				break;
			case "1025":
				$ErrorDesc = "امضا تراکنش نامعتبر است";
				break;
			case "1026":
				$ErrorDesc = "شماره سفارش تراکنش نامعتبر است";
				break;
			case "1027":
				$ErrorDesc = "شماره پذيرنده نامعتبر است";
				break;
			case "1028":
				$ErrorDesc = "شماره ترمینال پذيرنده نامعتبر است";
				break;
			case "1029":
				$ErrorDesc = "آدرس IP پرداخت در محدوده آدرس های معتبر اعلام شده توسط پذيرنده نیست. لطفا مسئول فنی پذيرنده با بانک تماس حاصل فرمايند";
				break;
			case "1030":
				$ErrorDesc = "آدرس Domain پرداخت در محدوده آدرس های معتبر اعلام شده توسط پذيرنده نیست. لطفا مسئول فنی پذيرنده با بانک تماس حاصل فرمايند";
				break;
			case "1031":
				$ErrorDesc = "مهلت زمانی شما جهت پرداخت به پايان رسیده است.لطفا مجددا سعی بفرمايید.";
				break;
			case "1032":
				$ErrorDesc = "پرداخت با اين کارت، برای پذيرنده مورد نظر شما امکان پذير نیست. لطفا از کارتهای مجاز که توسط پذيرنده معرفی شده است. استفاده نمايید.";
				break;
			case "1033":
				$ErrorDesc = "به علت مشکل در سايت پذيرنده. پرداخت برای اين پذيرنده غیرفعال شده است. لطفاً مسوول فنی سايت پذيرنده با بانک تماس حاصل فرمايند. ";
				break;
			case "1036":
				$ErrorDesc = "اطلاعات اضافی ارسال نشده يا دارای اشکال است";
				break;
			case "1037":
				$ErrorDesc = "شماره پذيرنده يا شماره ترمینال پذيرنده صحیح نمیباشد";
				break;
			case "1053":
				$ErrorDesc = "خطا: درخواست معتبر، از سمت پذيرنده صورت نگرفته است لطفاً اطلاعات پذيرنده خود را چک کنید.";
				break;
			case "1055":
				$ErrorDesc = "مقدار غیرمجاز در ورود اطلاعات";
				break;
			case "1056":
				$ErrorDesc = "سیستم موقتاً قطع میباشد.لطفاً بعداً تلاش فرمايید.";
				break;
			case "1058":
				$ErrorDesc = "سرويس پرداخت اينترنتی خارج از سرويس می باشد.لطفاً بعداً سعی فرمایید.";
				break;
			case "1061":
				$ErrorDesc = "اشکال در تولید کد يکتا، لطفاً مرورگر خود را بسته و با اجرای مجدد مرورگر عملیات پرداخت را انجام دهید. احتمال استفاده از دکمه Back مرورگر";
				break;
			case "1064":
				$ErrorDesc = "لطفاً مجدداً سعی بفرمايید";
				break;
			case "1065":
				$ErrorDesc = "ارتباط ناموفق، لطفاً چند لحظه ديگر مجدداً سعی کنید.";
			case "1066":
				$ErrorDesc = "سیستم سرويس دهی پرداخت موقتا غیر فعال شده است";
				break;
			case "1068":
				$ErrorDesc = "با عرض پوزش به علت بروزرسانی، سیستم موقتا قطع میباشد.";
				break;
			case "1072":
				$ErrorDesc = "خطا در پردازش پارامترهای اختیاری پذيرنده";
				break;
			case "1101":
				$ErrorDesc = "مبلغ تراکنش نامعتبر است";
				break;
			case "1103":
				$ErrorDesc = "توکن ارسالی نامعتبر است";
				break;
			case "1104":
				$ErrorDesc = "اطلاعات تسهیم صحیح نیست";
				break;
			case "1021":
				$ErrorDesc = "پذيرنده غیرفعال است. لطفاً جهت فعالسازی با شرکت سداد تماس بگیريد. شماره تماس: 02142739000";
				break;
			default:
				$ErrorDesc = "خطای نا مشخص: این خطا به دلیل عدم پاسخگویی از سوی وب سرویس شرکت سداد به وجود می آید؛ برای اطلاع از دلیل این خطا با کارشناسان شرکت داده ورزی سداد تماس حاصل نمایید.
                        <a target='blank' href='" . get_site_url() . "/bmi_error.html'>لینک نتیجه دریافتی از بانک</a>";
		}

		return $ErrorDesc;
	}

	//bank errors
	private function getVerifyErrorMessage( $ErrorCode ) {
		$ErrorDesc = "";
		switch ( $ErrorCode ) {
			case "-2":
				$ErrorDesc = "خطای نا مشخص: این خطا به دلیل عدم پاسخگویی از سوی وب سرویس شرکت سداد به وجود می آید؛ برای اطلاع از دلیل این خطا با کارشناسان شرکت داده ورزی سداد تماس حاصل نمایید. هم چنین اگر پولی از کارت شما کسر شده باشد حداکثر تا 24 ساعت آینده به حساب شما بازگشت داده خواهد شد.
                        <a target='blank' href='" . get_site_url() . "/bmi_error.html'>لینک نتیجه دریافتی از بانک</a>";
				break;
			case "0":
				$ErrorDesc = "نتیجه تراکنش موفق است";
				break;
			case "1":
				$ErrorDesc = "درخواست تکراريست(قبلا در سیستم با موفقیت ثبت شده است)";
				break;
			case "-1":
				$ErrorDesc = "پارامترهای ارسالی صحیح نیست و يا تراکنش در سیستم وجود ندارد.";
				break;
			case "101":
				$ErrorDesc = "مهلت ارسال تراکنش به پايان رسیده است";
				break;
			default:
				$ErrorDesc = "خطای نا مشخص: این خطا به دلیل عدم پاسخگویی از سوی وب سرویس شرکت سداد به وجود می آید؛ برای اطلاع از دلیل این خطا با کارشناسان شرکت داده ورزی سداد تماس حاصل نمایید. هم چنین اگر پولی از کارت شما کسر شده باشد حداکثر تا 24 ساعت آینده به حساب شما بازگشت داده خواهد شد.
                        <a target='blank' href='" . get_site_url() . "/bmi_error.html'>لینک نتیجه دریافتی از بانک</a>";
		}

		return $ErrorDesc;
	}

}
