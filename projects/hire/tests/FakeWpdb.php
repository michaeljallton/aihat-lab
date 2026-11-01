<?php
/**
 * The subset of wpdb the plugin uses, on in-memory SQLite. Tables are built
 * from Installer::schema() with the MySQL-only parts translated, so tests run
 * against the same columns the plugin creates.
 */
class FakeWpdb {

	public string $prefix = 'wp_';
	public int $insert_id = 0;
	public ?PDO $pdo = null;

	public function __construct() {
		$this->reset();
	}

	public function reset(): void {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
	}

	/**
	 * Creates the plugin tables from the dbDelta definitions.
	 */
	public function create_tables( array $schema ): void {
		foreach ( $schema as $sql ) {
			$this->pdo->exec( self::to_sqlite( $sql ) );
		}
	}

	private static function to_sqlite( string $sql ): string {
		preg_match( '/CREATE TABLE (\S+) \((.*)\)/s', $sql, $m );
		$table   = $m[1];
		$lines   = array_map( 'trim', explode( "\n", trim( $m[2] ) ) );
		$cols    = array();
		$uniques = array();
		$pk      = '';
		foreach ( $lines as $line ) {
			$line = rtrim( $line, ',' );
			if ( '' === $line ) {
				continue;
			}
			if ( preg_match( '/^PRIMARY KEY\s+\((\w+)\)/', $line, $k ) ) {
				$pk = '__done' === $pk ? $pk : $k[1];
				continue;
			}
			if ( preg_match( '/^UNIQUE KEY \w+ \(([\w,]+)\)/', $line, $k ) ) {
				$uniques[] = $k[1];
				continue;
			}
			if ( preg_match( '/^KEY /', $line ) ) {
				continue;
			}
			preg_match( '/^(\w+)\s+(\w+)/', $line, $c );
			$name = $c[1];
			$type = strtolower( $c[2] );
			$sqlt = in_array( $type, array( 'bigint', 'int', 'tinyint' ), true ) ? 'INTEGER' : ( 'decimal' === $type ? 'REAL' : 'TEXT' );
			$def  = '';
			if ( preg_match( "/DEFAULT ('[^']*'|[\d.]+)/", $line, $d ) ) {
				$def = ' DEFAULT ' . $d[1];
			}
			$notnull = str_contains( $line, 'NOT NULL' ) ? ' NOT NULL' : '';
			if ( str_contains( $line, 'AUTO_INCREMENT' ) ) {
				$cols[] = "{$name} INTEGER PRIMARY KEY AUTOINCREMENT";
				$pk     = '__done';
				continue;
			}
			$cols[] = "{$name} {$sqlt}{$notnull}{$def}";
		}
		if ( $pk && '__done' !== $pk ) {
			foreach ( $cols as $i => $col ) {
				if ( str_starts_with( $col, $pk . ' ' ) ) {
					$cols[ $i ] = $col . ' PRIMARY KEY';
				}
			}
		}
		foreach ( $uniques as $u ) {
			$cols[] = "UNIQUE ({$u})";
		}
		return "CREATE TABLE {$table} (" . implode( ', ', $cols ) . ')';
	}

	public function prepare( string $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback(
			'/%[dsf]/',
			function ( $m ) use ( &$i, $args ) {
				$v = $args[ $i++ ] ?? null;
				switch ( $m[0] ) {
					case '%d':
						return (string) (int) $v;
					case '%f':
						return (string) (float) $v;
					default:
						return $this->pdo->quote( (string) $v );
				}
			},
			$query
		);
	}

	public function get_row( $query, $output = ARRAY_A ) {
		$row = $this->pdo->query( $query )->fetch( PDO::FETCH_ASSOC );
		return $row ?: null;
	}

	public function get_results( $query, $output = ARRAY_A ) {
		return $this->pdo->query( $query )->fetchAll( PDO::FETCH_ASSOC );
	}

	public function get_var( $query ) {
		$v = $this->pdo->query( $query )->fetchColumn();
		return false === $v ? null : $v;
	}

	public function query( $query ) {
		try {
			return $this->pdo->exec( $query );
		} catch ( PDOException $e ) {
			return false;
		}
	}

	public function insert( $table, $data ) {
		$cols = array_keys( $data );
		$sql  = "INSERT INTO {$table} (" . implode( ',', $cols ) . ') VALUES (' . implode( ',', array_fill( 0, count( $cols ), '?' ) ) . ')';
		try {
			$st = $this->pdo->prepare( $sql );
			$st->execute( array_values( $data ) );
		} catch ( PDOException $e ) {
			return false;
		}
		$this->insert_id = (int) $this->pdo->lastInsertId();
		return 1;
	}

	public function update( $table, $data, $where ) {
		$set = implode( ',', array_map( static fn( $c ) => "{$c} = ?", array_keys( $data ) ) );
		$wh  = implode( ' AND ', array_map( static fn( $c ) => "{$c} = ?", array_keys( $where ) ) );
		$st  = $this->pdo->prepare( "UPDATE {$table} SET {$set} WHERE {$wh}" );
		$st->execute( array_merge( array_values( $data ), array_values( $where ) ) );
		return $st->rowCount();
	}
}
