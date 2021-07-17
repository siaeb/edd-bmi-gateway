<?php

namespace siaeb\edd\gateways\bmi\includes;

class Initializer {

	/**
	 * @var BMIGateway
	 */
	private $_gateway;

	public function __construct() {
		$this->_gateway = new BmiGateway();
	}

}
