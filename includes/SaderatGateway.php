<?php

namespace siaeb\edd\gateways\saderat\includes;

class SaderatGateway {

	protected $data = [
		'bankKey'       => 'Saderat',
		'adminLabel'    => 'بانک صادرات',
		'checkoutLabel' => 'بانک صادرات',
		'priority'      => 11,
	];

	public function __construct() {
		add_filter( 'edd_payment_gateways', [ $this, 'registerGateway' ], $this->data['priority'] );
		add_filter( 'edd_settings_gateways', [ $this, 'registerSettings' ], $this->data['priority'] );
		add_filter( 'edd_' . $this->data['bankKey'] . '_cc_form', [ $this, 'ccForm' ], $this->data['priority'] );
		add_action( 'edd_gateway_' . $this->data['bankKey'], [ $this, 'processPayment' ], $this->data['priority'] );
		add_action( 'init', [ $this, 'verifyPayment' ] );
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

	public function registerSettings( $settings ) {
		$Saderat_settings = [
			[
				'id'   => 'Saderat_settings',
				'name' => '<strong>بانک صادرات</strong>',
				'type' => 'header'
			],
			[
				'id'   => 'Saderat_TermID',
				'name' => 'کد فروشنده',
				'desc' => '',
				'type' => 'text',
				'size' => 'medium'
			],
			[
				'id'   => 'Saderat_UserName',
				'name' => 'نام کاربري',
				'desc' => '',
				'type' => 'text',
				'size' => 'medium'
			],
			[
				'id'   => 'Saderat_PassWord',
				'name' => 'رمز',
				'desc' => '',
				'type' => 'text',
				'size' => 'medium'
			]
		];
		return array_merge( $settings, $Saderat_settings );
	}

	/**
	 * Process payment
	 *
	 * @since 1.0
	 * @param $purchaseData
	 */
	public function processPayment( $purchaseData ) {
		global $edd_options;
		error_reporting( 0 );
		$bps_ws = 'https://sep.shaparak.ir/Payments/InitPayment.asmx?WSDL';

		$i = 0;
		do {
			$soapClient = new nusoap_client( $bps_ws, 'wsdl' );
			$soapProxy  = $soapClient->getProxy();
			$i ++;
		} while ( $soapClient->getError() and $i < 3 );

		// Check for Connection error
		if ( $soapClient->getError() ) {
			edd_set_error( 'pay_00', 'P00:خطایی در اتصال پیش آمد،مجدد تلاش کنید...' );
			edd_send_back_to_checkout( '?payment-mode=' . $purchaseData['post_data']['edd-gateway'] );
		}

		$payment_data = [
			'price'        => $purchaseData['price'],
			'date'         => $purchaseData['date'],
			'user_email'   => $purchaseData['post_data']['edd_email'],
			'purchase_key' => $purchaseData['purchase_key'],
			'currency'     => $edd_options['currency'],
			'downloads'    => $purchaseData['downloads'],
			'cart_details' => $purchaseData['cart_details'],
			'user_info'    => $purchaseData['user_info'],
			'status'       => 'pending',
		];

		$payment = edd_insert_payment( $payment_data );


		$post_url = "https://sep.shaparak.ir/Payment.aspx";

		$terminalId   = isset($edd_options['Saderat_TermID']) ? $edd_options['Saderat_TermID'] : '';
		$userName     = isset($edd_options['Saderat_UserName']) ? $edd_options['Saderat_UserName'] : '';
		$userPassword = isset($edd_options['Saderat_PassWord']) ? $edd_options['Saderat_PassWord'] : '';

		if ( $payment ) {

			$_SESSION['bsi_payment_data'] = $payment_data;
			$_SESSION['Saderat_payment']  = $payment;

			$return         = add_query_arg( 'order', $this->data['bankKey'], get_permalink( $edd_options['success_page'] ) );
			$orderId        = date( 'ym' ) . date( 'His' ) . $payment;
			$amount         = $purchaseData['price'];
			$localDate      = date( "Ymd" );
			$localTime      = date( "His" );
			$additionalData = "Purchase key: " . $purchaseData['purchase_key'];
			$payerId        = 0;
			$i              = 0;

			do {
				$PayResult = $this->sendRequest( $soapProxy, $terminalId, $orderId, $amount );
				$i ++;
			} while ( $PayResult == "-2" and $i < 3 );

			///************END of PAY REQUEST***************///
			if ( $PayResult != "-2" ) {
				// Successfull Pay Request
				echo '
                <form id="SaderatPay" name="SaderatPay" method="post" action="' . $post_url . '">
                <input type="hidden" name="Token" value="' . $PayResult . '">
                <input type="hidden" name="RedirectURL" value="' . $return . '">
                </form> 
                <script type="text/javascript">
                      function setAction(element_id)   
                                { 
                                   var frm = document.getElementById(element_id);
                                   if(frm)
                                   {
                                   frm.action = ' . "'https://sep.shaparak.ir/Payment.aspx'" . ';  
                                   }
                                } 
                                setAction(' . "'SaderatPay'" . ');
                 </script>       
				<script type="text/javascript">document.SaderatPay.submit();</script>
                
			';
				exit();
			} else {
				edd_update_payment_status( $payment, 'failed' );
				edd_insert_payment_note( $payment, 'P02:' . $this->getStatusMessage( (int) $PayResult ) );
				edd_set_error( 'pay_02', ':P02' . $this->getStatusMessage( (int) $PayResult ) );
				edd_send_back_to_checkout( '?payment-mode=' . $purchaseData['post_data']['edd-gateway'] );
			}
		} else {
			edd_set_error( 'pay_01', 'P01:خطا در ایجاد پرداخت، لطفاً مجدداً تلاش کنید...' );
			edd_send_back_to_checkout( '?payment-mode=' . $purchaseData['post_data']['edd-gateway'] );
		}
	}

	/**
	 * Verify payment
	 *
	 * @since 1.0
	 */
	public function verifyPayment() {
		global $edd_options;

		$terminalId   = isset($edd_options['Saderat_TermID']) ? $edd_options['Saderat_TermID'] : '';
		$userName     = isset($edd_options['Saderat_UserName']) ? $edd_options['Saderat_UserName'] : '';
		$userPassword = isset($edd_options['Saderat_PassWord']) ? $edd_options['Saderat_PassWord'] : '';
		$bps_ws = 'https://acquirer.samanepay.com/payments/referencepayment.asmx?WSDL';

		if ( isset( $_GET['order'] ) && $_GET['order'] == $this->data['bankKey'] && isset( $_POST['TRACENO'] ) && $_SESSION['Saderat_payment'] == substr( $_POST['ResNum'], 10 ) && $_POST['State'] == "OK" ) {
			$payment = $_SESSION['Saderat_payment'];
			$State   = $_POST['State'];
			$TraceNo = $_POST['TRACENO'];//شماره رهگیری
			$RefNum  = $_POST['RefNum'];//رسید دیجیتالی
			$ResNum  = $_POST['ResNum']; //orderid

			$do_inquiry  = false;
			$do_settle   = false;
			$do_reversal = false;
			$do_publish  = false;

			//Connect to WebService
			$i = 0;
			do {
				$soapClient     = new nusoap_client( $bps_ws, 'wsdl' );
				$soapProxy = $soapClient->getProxy();
				$i ++;
			} while ( $soapClient->getError() and $i < 5 );//Check for connection errors

			if ( $soapClient->getError() ) {
				edd_set_error( 'ver_00', 'V00:تراکنش ناموفق بود.<br>اگر وجهی از حساب شما کسر شده باشد، تا پایان روز جاری به حساب شما باز خواهد گشت.' );
				edd_update_payment_status( $payment, 'failed' );
				edd_insert_payment_note( $payment, 'V00:' . '<pre>' . $soapClient->getError() . '</pre>' );
				edd_send_back_to_checkout( '?payment-mode=' . $this->data["bankKey"] );
			}

			if ( ! edd_is_test_mode() ) {
				$VerResult = $soapProxy->VerifyTransaction( $RefNum, $terminalId );
				if ( $VerResult > 0 ) {
					$do_reversal = false;
					$do_publish  = true;
				} else {
					$do_reversal = true;
					$do_publish  = false;
				}
			} else {
				//in test mode
				$do_reversal = true;
				$do_publish  = false;
			}

			if ( $do_reversal ) {
				$i = 0;
				do {
					//REVERSAL REQUEST
					$soapclient = new nusoap_client( 'https://acquirer.samanepay.com/payments/referencepayment.asmx?WSDL', 'wsdl' );
					$soapProxy = $soapclient->getProxy();
					if ( $err = $soapclient->getError() ) {
						edd_set_error( 'rev_00', 'R00:تراکنش ناموفق بود.<br>اگر وجهی از حساب شما کسر شده باشد، تا پایان روز جاری به حساب شما باز خواهد گشت.' );
						edd_update_payment_status( $payment, 'failed' );
						edd_insert_payment_note( $payment, 'R00:' . '<pre>' . $err . '</pre>' );
						edd_send_back_to_checkout( '?payment-mode=' . $this->data['bankKey'] );
					}
					$res = $soapProxy->reverseTransaction( $RefNum, $terminalId, $userName, $userPassword );
					$i ++;
				} while ( $res != 1 and $i < 5 );
				// Note: Successful Reversal means that sale is reversed.
				edd_update_payment_status( $payment, 'failed' );
				edd_insert_payment_note( $payment, 'REV:' . $this->getStatusMessage( (int) $res ) );
				edd_set_error( 'rev_' . $res, 'R00:تراکنش ناموفق بود.<br>اگر وجهی از حساب شما کسر شده باشد، تا پایان روز جاری به حساب شما باز خواهد گشت.' );
				edd_send_back_to_checkout( '?payment-mode=' . $this->data['bankKey'] );
				$do_publish  = false;
				$do_reversal = false;
			}

			if ( $do_publish == true ) {
				// Publish Payment
				$_SESSION['bsi_TraceNo'] = $TraceNo;

				$do_publish = false;
				edd_update_payment_status( $payment, 'publish' );
				edd_insert_payment_note( $payment, 'شماره تراکنش:' . $TraceNo );

				echo "<script type='text/javascript'>alert('کد تراکنش خرید بانک : " . $TraceNo . "');</script>";

			}
		} else if ( isset( $_GET['order'] ) and $_GET['order'] == $this->data['bankKey'] and isset( $_POST['TRACENO'] ) and $_SESSION['Saderat_payment'] == substr( $_POST['ResNum'], 10 ) and $_POST['State'] != 'OK' ) {
			edd_update_payment_status( $_SESSION['Saderat_payment'], 'failed' );
			edd_insert_payment_note( $_SESSION['Saderat_payment'], 'V02:' . $this->getStatusMessage( (int) $_POST['State'] ) );
			edd_set_error( $_POST['State'], $this->getStatusMessage( (int) $_POST['State'] ) );
			edd_send_back_to_checkout( '?payment-mode=' . $this->data['bankKey'] );
		}
	}

	/**
	 * CC Form
	 *
	 * @return mixed
	 */
	public function ccForm() {
		return;
	}

	private function getStatusMessage( $code ) {
		$tmess = "شرح خطا: ";
		switch ( $code ) {
			////Requset errors
			case - 1:
				$tmess .= "خطا در پردازش اطلاعات ارسالی";
				break;
			case - 2:
				$tmess .= "خطا در اتصال به سامانه بانکی";
				break;
			case - 3:
				$tmess .= "ورودیها حاوی کارکترهای غیرمجاز می‌باشند.";
				break;
			case - 4:
				$tmess .= "Merchant Authentication Failed ( کلمه عبور یا کد فروشنده اشتباه است).";
				break;
			case - 6:
				$tmess .= "سند قبلا برگشت کامل یافته است.";
				break;

			case - 7:
				$tmess .= "رسید دیجیتالی تهی است.";
				break;

			case - 8:
				$tmess .= "طول ورودی ها بیشتر از حد مجاز است.";
				break;

			case - 9:
				$tmess .= "وجود کارکترهای غیرمجاز در مبلغ برگشتی.";
				break;

			case - 10:
				$tmess .= "رسید دیجیتالی به صورت Base64 نیست(حاوی کاراکترهای غیرمجاز است).";
				break;

			case - 11:
				$tmess .= "طول ورودی‌ها بیشتر از حد مجاز است.";
				break;

			case - 12:
				$tmess .= "مبلغ برگشتی منفی است.";
				break;

			case - 13:
				$tmess .= "مبلغ برگشتی برای برگشت جزئی بیش از مبلغ برگشت نخورده ی رسید دیجیتالی است.";
				break;

			case - 14:
				$tmess .= "چنین تراکنشی تعریف نشده است.";
				break;

			case - 15:
				$tmess .= "مبلغ برگشتی به صورت اعشاری داده شده است.";
				break;

			case - 16:
				$tmess .= "خطای داخلی سیستم";
				break;

			case - 17:
				$tmess .= "برگشت زدن جزیی تراکنش مجاز نمی باشد.";
				break;

			case - 18:
				$tmess .= "IP Address فروشنده نا معتبر است.";
				break;
			//verify errors
			case "Canceled By User":
				$tmess .= "تراکنش توسط خریدار کنسل شده است.";
				break;

			case "Invalid Amount":
				$tmess .= "مبلغ سند برگشتی، از مبلغ تراکنش اصلی بیشتر است.";
				break;

			case "Invalid Transaction":
				$tmess .= " برگشت یک تراکنش رسیده است، در حالی که تراکنش اصلی پیدا نمی شود.";
				break;

			case "Invalid Card Number":
				$tmess .= "شماره کارت اشتباه است.";
				break;

			case "No Such Issuer":
				$tmess .= "چنین صادر کننده کارتی وجود ندارد.";
				break;

			case "Expired Card Pick Up":
				$tmess .= "از تاریخ انقضای کارت گذشته است و کارت دیگر معتبر نیست.";
				break;

			case "Allowable PIN Tries Exceeded Pick Up":
				$tmess .= "رمز کارت( PIN ) بیش از 3 مرتبه اشتباه وارد شده است در نتیجه کارت غیرفعال خواهد شد.";
				break;

			case "Incorrect PIN":
				$tmess .= "خریدار رمز کارت ( PIN ) را اشتباه وارد کرده است.";
				break;

			case "Exceeds Withdrawal Amount Limit":
				$tmess .= "مبلغ بیش از سقف برداشت می باشد.";
				break;

			case "Transaction Cannot Be Completed":
				$tmess .= "تراکنش Authorize شده است (شماره PIN و PAN درست هستند) ولی امکان سند خوردن وجود ندارد.";
				break;

			case "Response Received Too Late":
				$tmess .= "تراکنش در شبکه بانکی Timeout خورده است.";
				break;

			case "Suspected Fraud Pick Up":
				$tmess .= "خریدار یا فیلد CVV2 و یا فیلد ExpDate را اشتباه وارد کرده است (یا اصلا وارد نکرده است).";
				break;

			case "No Sufficient Funds":
				$tmess .= "موجودی حساب خریدار، کافی نیست.";
				break;

			case "Issuer Down Slm":
				$tmess .= "سیستم بانک صادر کننده کارت خریدار، در وضعیت عتلیاتی نیست.";
				break;

			case "TME Error":
				$tmess .= "کلیه خطاهای دیگر بانکی باعث ایجاد چنین خطایی می گردد.";
				break;

			default:
				$tmess .= "خطای تعریف نشده";
		}

		return $code . ' : ' . $tmess;
	}

	private function sendRequest( &$soapClient, $TermID, $ResNUM, $TotalAmount ) {
		$res = $soapClient->RequestToken( $TermID, $ResNUM, $TotalAmount );
		if ( $res == '' ) {
			$res = "-2";
		}
		return $res;
	}

}
