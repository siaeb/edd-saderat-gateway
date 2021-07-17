<?php

namespace siaeb\edd\gateways\saderat\includes;

class Initializer {

	/**
	 * @var SaderatGateway
	 */
	private $_gateway;

	public function __construct() {
		$this->_gateway = new SaderatGateway();
	}

}
