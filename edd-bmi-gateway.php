<?php
/**
 * Plugin Name: درگاه بانک ملی برای Easy Digital Downloads
 * Plugin URI: http://www.siaeb.com
 * Description: با استفاده از این افزونه شما می توانید درگاه پرداخت بانک ملی را به فروشگاه مجازی خود اضافه کنید.
 * Author: سیاوش ابراهیمی
 * Author URI: http://www.siaeb.com
 * Version: 1.0
 */

use siaeb\edd\gateways\bmi\includes\Initializer;

if ( ! class_exists( 'SIAEB_EDD_BMI_GATEWAY' ) ) :

	final class SIAEB_EDD_BMI_GATEWAY {

		/**
		 * @var SIAEB_EDD_BMI_GATEWAY The one true SIAEB_EDD_BMI_GATEWAY
		 *
		 * @since 1.0.0
		 */
		private static $instance;

		public static function instance() {

			if ( ! isset( self::$instance ) && ! ( self::$instance instanceof SIAEB_EDD_BMI_GATEWAY) ) {
				self::$instance = new SIAEB_EDD_BMI_GATEWAY();
				self::$instance->constants();
				self::$instance->includes();
				self::$instance->init();
			}

			return self::$instance;
		}

		/**
		 * Throw error on object clone.
		 *
		 * The whole idea of the singleton design pattern is that there is a single
		 * object therefore, we don't want the object to be cloned.
		 *
		 * @since 1.0
		 * @access protected
		 * @return void
		 */
		public function _clone() {
			_doing_it_wrong( __FUNCTION__, __( 'Cheatin&#8217; huh?', 'siaeb-edd-bmig' ), '1.0.0' );
		}

		/**
		 * Disable unserializing of the class.
		 *
		 * @since 1.0
		 * @access protected
		 * @return void
		 */
		public function _wakeup() {
			_doing_it_wrong( __FUNCTION__, __( 'Cheatin&#8217; huh?', 'siaeb-edd-bmig' ), '1.0.0' );
		}

		/**
		 * Initialize plugin classes
		 *
		 * @since 1.0
		 * @access private
		 * @return void
		 */
		private function init() {
			new Initializer();
		}

		/**
		 * Setup plugin constants.
		 *
		 * @access private
		 * @since 1.0
		 * @return void
		 */
		private function constants() {
			$this->define_constant('SIAEB_EDDBMIG_PREFIX', 'siaeb_fnt_');
			$this->define_constant('SIAEB_EDDBMIG_DIR', trailingslashit( plugin_dir_path( __FILE__ ) ));
			$this->define_constant('SIAEB_EDDBMIG_INC_DIR', SIAEB_EDDBMIG_DIR . 'includes');
			$this->define_constant('SIAEB_EDDBMIG_FILE', __FILE__);
			$this->define_constant( 'SIAEB_EDDBMIG_PLUGIN_URL', trailingslashit( plugin_dir_url( __FILE__ ) ) );
		}


		/**
		 * Define constant
		 *
		 * @since 1.0
		 * @param $name
		 * @param $value
		 */
		private function define_constant($name, $value) {
			if (!defined($name)) {
				define($name, $value);
			}
		}

		/**
		 * Include required files.
		 *
		 * @access private
		 * @since 1.0
		 * @return void
		 */
		private function includes() {
			include_once SIAEB_EDDBMIG_INC_DIR . '/nusoap.php';
			include_once SIAEB_EDDBMIG_INC_DIR . '/Initializer.php';
			include_once SIAEB_EDDBMIG_INC_DIR . '/BmiGateway.php';
		}

	}

endif;

if (!function_exists('siaeb_edd_bmig')) {
	/**
	 * The main function for that returns SIAEB_EDD_BMI_GATEWAY
	 *
	 *
	 * Use this function like you would a global variable, except without needing
	 * to declare the global.
	 *
	 * Example: <?php $instance = siaeb_edd_bmig(); ?>
	 *
	 * @since 1.0
	 * @return object|SIAEB_EDD_BMI_GATEWAY The one true SIAEB_EDD_BMI_GATEWAY Instance.
	 */
	function siaeb_edd_bmig() {
		return SIAEB_EDD_BMI_GATEWAY::instance();
	}
}

siaeb_edd_bmig();
