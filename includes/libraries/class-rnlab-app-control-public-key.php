<?php

use Firebase\JWT\JWT;

/**
 * Class Rnlab_App_Control_Public_Key
 * @author ngocdt@rnlab.io
 * @since 1.2.3
 */
class Rnlab_App_Control_Public_Key {

	/**
	 * @param $kid
	 *
	 * @return array
	 * @throws Exception
	 */
	public static function getPublicKey( $kid ) {
		$publicKeys        = file_get_contents( 'https://appleid.apple.com/auth/keys' );
		$decodedPublicKeys = json_decode( $publicKeys, true );

		if ( ! isset( $decodedPublicKeys['keys'] ) || count( $decodedPublicKeys['keys'] ) < 1 ) {
			throw new Exception( 'Invalid key format.' );
		}

		$key = array_search( $kid, array_column( $decodedPublicKeys['keys'], 'kid' ) );

		if ( $key === false ) {
			throw new UnexpectedValueException( '"kid" empty, unable to lookup correct key' );
		}

		$parsedKeyData    = $decodedPublicKeys['keys'][ $key ];
		$parsedPublicKey  = self::parseKey( $parsedKeyData );
		$publicKeyDetails = openssl_pkey_get_details( $parsedPublicKey );

		if ( ! isset( $publicKeyDetails['key'] ) ) {
			throw new Exception( 'Invalid public key details.' );
		}

		return [
			'publicKey' => $publicKeyDetails['key'],
			'alg'       => $parsedKeyData['alg']
		];

	}

	/**
	 * Parse a JWK key
	 *
	 * @param array $jwk An individual JWK
	 *
	 * @return resource|array An associative array that represents the key
	 *
	 * @throws InvalidArgumentException     Provided JWK is empty
	 * @throws UnexpectedValueException     Provided JWK was invalid
	 * @throws DomainException              OpenSSL failure
	 *
	 * @uses createPemFromModulusAndExponent
	 */
	private static function parseKey( array $jwk ) {
		if ( empty( $jwk ) ) {
			throw new InvalidArgumentException( 'JWK must not be empty' );
		}
		if ( ! isset( $jwk['kty'] ) ) {
			throw new UnexpectedValueException( 'JWK must contain a "kty" parameter' );
		}

		switch ( $jwk['kty'] ) {
			case 'RSA':
				if ( \array_key_exists( 'd', $jwk ) ) {
					throw new UnexpectedValueException( 'RSA private keys are not supported' );
				}
				if ( ! isset( $jwk['n'] ) || ! isset( $jwk['e'] ) ) {
					throw new UnexpectedValueException( 'RSA keys must contain values for both "n" and "e"' );
				}

				$pem       = self::createPemFromModulusAndExponent( $jwk['n'], $jwk['e'] );
				$publicKey = \openssl_pkey_get_public( $pem );
				if ( false === $publicKey ) {
					throw new DomainException(
						'OpenSSL error: ' . \openssl_error_string()
					);
				}

				return $publicKey;
			default:
				// Currently only RSA is supported
				break;
		}
	}

	/**
	 * Create a public key represented in PEM format from RSA modulus and exponent information
	 *
	 * @param string $n The RSA modulus encoded in Base64
	 * @param string $e The RSA exponent encoded in Base64
	 *
	 * @return string The RSA public key represented in PEM format
	 *
	 * @uses encodeLength
	 */
	private static function createPemFromModulusAndExponent( $n, $e ) {
		$modulus        = JWT::urlsafeB64Decode( $n );
		$publicExponent = JWT::urlsafeB64Decode( $e );

		$components = array(
			'modulus'        => \pack( 'Ca*a*', 2, self::encodeLength( \strlen( $modulus ) ), $modulus ),
			'publicExponent' => \pack( 'Ca*a*', 2, self::encodeLength( \strlen( $publicExponent ) ), $publicExponent )
		);

		$rsaPublicKey = \pack(
			'Ca*a*a*',
			48,
			self::encodeLength( \strlen( $components['modulus'] ) + \strlen( $components['publicExponent'] ) ),
			$components['modulus'],
			$components['publicExponent']
		);

		// sequence(oid(1.2.840.113549.1.1.1), null)) = rsaEncryption.
		$rsaOID       = \pack( 'H*', '300d06092a864886f70d0101010500' ); // hex version of MA0GCSqGSIb3DQEBAQUA
		$rsaPublicKey = \chr( 0 ) . $rsaPublicKey;
		$rsaPublicKey = \chr( 3 ) . self::encodeLength( \strlen( $rsaPublicKey ) ) . $rsaPublicKey;

		$rsaPublicKey = \pack(
			'Ca*a*',
			48,
			self::encodeLength( \strlen( $rsaOID . $rsaPublicKey ) ),
			$rsaOID . $rsaPublicKey
		);

		$rsaPublicKey = "-----BEGIN PUBLIC KEY-----\r\n" .
		                \chunk_split( \base64_encode( $rsaPublicKey ), 64 ) .
		                '-----END PUBLIC KEY-----';

		return $rsaPublicKey;
	}

	/**
	 * DER-encode the length
	 *
	 * DER supports lengths up to (2**8)**127, however, we'll only support lengths up to (2**8)**4.  See
	 * {@link http://itu.int/ITU-T/studygroups/com17/languages/X.690-0207.pdf#p=13 X.690 paragraph 8.1.3} for more information.
	 *
	 * @param int $length
	 *
	 * @return string
	 */
	private static function encodeLength( $length ) {
		if ( $length <= 0x7F ) {
			return \chr( $length );
		}

		$temp = \ltrim( \pack( 'N', $length ), \chr( 0 ) );

		return \pack( 'Ca*', 0x80 | \strlen( $temp ), $temp );
	}
}