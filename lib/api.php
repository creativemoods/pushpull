<?php
/**
 * Interfaces with the GitHub API
 * @package PushPull
 */

require_once __DIR__ . '/persist.php';
require_once __DIR__ . '/fetch.php';

/**
 * Class PushPull_Api
 */
class PushPull_Api {

	/**
	 * Application container.
	 *
	 * @var PushPull
	 */
	protected $app;

	/**
	 * GitHub fetch client.
	 *
	 * @var PushPull_Fetch_Client
	 */
	protected $fetch;

	/**
	 * Github persist client.
	 *
	 * @var PushPull_Persist_Client
	 */
	protected $persist;

	/**
	 * Instantiates a new Api object.
	 *
	 * @param PushPull $app Application container.
	 */
	public function __construct( PushPull $app ) {
		$this->app = $app;
	}

	/**
	 * Lazy-load fetch client.
	 *
	 * @return PushPull_Fetch_Client
	 */
	public function fetch() {
		if ( ! $this->fetch ) {
			$this->fetch = new PushPull_Fetch_Client( $this->app );
		}

		return $this->fetch;
	}

	/**
	 * Lazy-load persist client.
	 *
	 * @return PushPull_Persist_Client
	 */
	public function persist() {
		if ( ! $this->persist ) {
			$this->persist = new PushPull_Persist_Client( $this->app );
		}

		return $this->persist;
	}
}
