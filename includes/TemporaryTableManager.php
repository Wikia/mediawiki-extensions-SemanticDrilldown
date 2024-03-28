<?php
/**
 * Provides helper method to execute SQL queries in auto-commit mode
 */

class TemporaryTableManager {
	/** @var \SMW\MediaWiki\Connection\Database */
	private $databaseConnection;

	/**
	 * TemporaryTableManager constructor.
	 * @param \SMW\MediaWiki\Connection\Database $databaseConnection the DB connection to execute queries against
	 */
	public function __construct( $databaseConnection ) {
		$this->databaseConnection = $databaseConnection;
	}

	/**
	 * Execute the given SQL query against the database in auto-commit mode.
	 * If a transaction was already open (via DBO_TRX flag), it is commited.
	 *
	 * @param string $sqlQuery SQL query to execute
	 * @param string $method method name to log for query, defaults to this method
	 */
	public function queryWithAutoCommit( $sqlQuery, $method = __METHOD__ ) {
		// PLATFORM-9138: temporary table queries with leading spaces are not recognized properly by mediawiki
		$sqlQuery = trim( $sqlQuery );
		$this->databaseConnection->query( $sqlQuery, $method );
	}
}
