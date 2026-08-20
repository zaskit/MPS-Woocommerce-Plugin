<?php
/**
 * Decline code → what we actually tell the customer.
 *
 * The MPS Gateway plugin classifies declines into four colour buckets for the on-screen checkout
 * notice. That is deliberately coarse — it only has to answer "may I put this card in again?".
 * An email can do better: it has room to name the real reason and give one specific next step.
 *
 * 🛑 DIVISION OF AUTHORITY — read before editing a posture here.
 *
 * MPS_Decline_Codes owns the CLASSIFICATION. It is built from the client's colour-coded vSafe
 * sheet and it is what the checkout notice already tells the customer, so where that sheet has
 * ruled on a code, it rules here too — otherwise the on-screen message and the email would give
 * the same customer contradictory advice about the same decline.
 *
 * This class owns the WORDING: the per-code reason and next step an email has room for and a
 * one-line checkout notice does not. Where the sheet is SILENT (a code not listed on it at all),
 * the ISO 8583 response code the processor reports in brackets is used to reach better copy than
 * a shrug — the sheet is not being overridden there, it simply has nothing to say.
 *
 * Where a code's bespoke action text would contradict the sheet's bucket, the bucket wins and the
 * generic action for that bucket is used instead. The bespoke REASON is still shown: naming what
 * the bank actually said is always safe, it is only the instruction that must follow the sheet.
 *
 * @package MPS_Gateway
 */

defined( 'ABSPATH' ) || exit;

class MPS_Monitor_Copy {

	/**
	 * Postures are MPS_Decline_Codes' buckets, aliased so this file reads naturally.
	 * They are the SAME four values the checkout notice is driven by — not a parallel taxonomy.
	 */
	const RETRY_AFTER_BANK_CALL = MPS_Decline_Codes::CONTACT_ISSUER;
	const RETRY_FIX_DETAILS     = MPS_Decline_Codes::VERIFY_CARD;
	const RETRY_OTHER_CARD      = MPS_Decline_Codes::RETRY_OTHER;
	const DO_NOT_RETRY          = MPS_Decline_Codes::DO_NOT_RETRY;

	/**
	 * Processor code → copy.
	 *
	 * reason  — one sentence, customer-facing, no jargon, no blame.
	 * action  — the single most useful thing they can do next.
	 * posture — one of the constants above.
	 * alt     — is a non-card method worth offering for this decline? The email only shows the
	 *           block when the STORE actually has one enabled — the copy never names a method, so
	 *           nothing here can promise a payment option a merchant does not offer.
	 */
	private static function map(): array {
		return array(
			// ── The big one. 47% of all declines. Issuer blocking the cross-border/MCC combination.
			'1556' => array(
				'reason'  => 'Your bank blocked this specific purchase as a precaution. This is a restriction on the transaction, not a problem with your card — there is nothing wrong with the card itself.',
				'action'  => 'Call the number on the back of your card, tell them you are authorizing this purchase, then use the link below to try again. This clears it in almost every case.',
				'posture' => self::RETRY_AFTER_BANK_CALL,
				'alt'     => true,
			),
			'9011' => array(
				'reason'  => 'Your bank declined the transaction without giving a specific reason. This usually means their fraud screening did not recognise the purchase.',
				'action'  => 'A quick call to your card issuer to authorize the purchase will normally clear it. After that, use the link below to complete your order.',
				'posture' => self::RETRY_AFTER_BANK_CALL,
				'alt'     => true,
			),
			'1781' => array(
				'reason'  => 'Your bank does not permit this type of transaction on your card.',
				'action'  => 'Call your card issuer and ask them to allow this purchase, or pay with a different card.',
				'posture' => self::RETRY_AFTER_BANK_CALL,
				'alt'     => true,
			),
			'9053' => array(
				'reason'  => 'Your bank has asked that this transaction be authorized with them directly before it can go through.',
				'action'  => 'Please contact your card issuer before trying this card again.',
				'posture' => self::RETRY_AFTER_BANK_CALL,
				'alt'     => true,
			),
			'9912' => array(
				'reason'  => 'Your bank has asked that this transaction be authorized with them directly before it can go through.',
				'action'  => 'Please contact your card issuer before trying this card again.',
				'posture' => self::RETRY_AFTER_BANK_CALL,
				'alt'     => true,
			),

			// ── Fraud blocks. Retrying these is what gets a merchant account flagged.
			'9014' => array(
				'reason'  => 'Your bank\'s fraud protection system blocked this transaction.',
				'action'  => 'Please do not try this card again — repeated attempts can lock it. Speak to your bank first, or pay with a different card.',
				'posture' => self::DO_NOT_RETRY,
				'alt'     => true,
			),
			'9877' => array(
				'reason'  => 'Your bank declined this transaction on suspicion of fraud.',
				'action'  => 'Please do not try this card again. Contact your bank, or pay with a different card.',
				'posture' => self::DO_NOT_RETRY,
				'alt'     => true,
			),

			// ── Customer data errors. Cheap to fix, high recovery.
			'1784' => array(
				'reason'  => 'The security code (CVV) entered did not match the one your bank has on file.',
				'action'  => 'Use the link below and re-enter the 3-digit security code from the back of your card.',
				'posture' => self::RETRY_FIX_DETAILS,
				'alt'     => false,
			),
			'9552' => array(
				'reason'  => 'The security code (CVC) entered was not valid.',
				'action'  => 'Use the link below and re-enter the 3-digit code from the back of your card.',
				'posture' => self::RETRY_FIX_DETAILS,
				'alt'     => false,
			),
			'9069' => array(
				'reason'  => 'The card number entered was not recognised.',
				'action'  => 'Use the link below and check each digit of the card number before trying again.',
				'posture' => self::RETRY_FIX_DETAILS,
				'alt'     => false,
			),
			'9544' => array(
				'reason'  => 'Your bank could not find an account matching the card details entered.',
				'action'  => 'Use the link below and double-check the card number and expiry date, or try a different card.',
				'posture' => self::RETRY_FIX_DETAILS,
				'alt'     => true,
			),
			'1506' => array(
				'reason'  => 'The card has expired.',
				'action'  => 'Use the link below and enter the expiry date from your current card, or pay with a different card.',
				'posture' => self::RETRY_FIX_DETAILS,
				'alt'     => false,
			),
			'9086' => array(
				'reason'  => 'The card details entered could not be verified — most often an expiry date that has passed.',
				'action'  => 'Use the link below and check the card number and expiry date before trying again.',
				'posture' => self::RETRY_FIX_DETAILS,
				'alt'     => false,
			),
			'9051' => array(
				'reason'  => 'Your bank reported the card number or expiry date as no longer valid.',
				'action'  => 'Use the link below and check the details from your current card, or pay with a different card.',
				'posture' => self::RETRY_FIX_DETAILS,
				'alt'     => true,
			),

			// ── Limits and balance.
			'1502' => array(
				'reason'  => 'There were not enough available funds on the card to complete the purchase.',
				'action'  => 'You can complete the order with a different card.',
				'posture' => self::RETRY_OTHER_CARD,
				'alt'     => true,
			),
			'9845' => array(
				'reason'  => 'The card has reached its daily transaction limit.',
				'action'  => 'You can try again tomorrow, or complete the order now with a different card.',
				'posture' => self::RETRY_OTHER_CARD,
				'alt'     => true,
			),
			'9043' => array(
				'reason'  => 'The amount is above the per-transaction limit your bank allows on this card.',
				'action'  => 'Your bank may be able to lift the limit if you call them. Otherwise a different card will work.',
				'posture' => self::RETRY_AFTER_BANK_CALL,
				'alt'     => true,
			),

			// ── Card / merchant restrictions.
			'9524' => array(
				'reason'  => 'Your bank does not allow this card to be used with this type of merchant.',
				'action'  => 'Please use a different card to complete the order.',
				'posture' => self::RETRY_OTHER_CARD,
				'alt'     => true,
			),
			'9006' => array(
				'reason'  => 'The card is reported as inactive or closed.',
				'action'  => 'Please use a different card to complete the order.',
				'posture' => self::RETRY_OTHER_CARD,
				'alt'     => true,
			),
			'1782' => array(
				'reason'  => 'Your bank could not authorize the transaction on this card.',
				'action'  => 'Please try a different card to complete the order.',
				'posture' => self::RETRY_OTHER_CARD,
				'alt'     => true,
			),

			// ── Processor-side error, not the customer's fault.
			'9860' => array(
				'reason'  => 'A temporary error occurred while the payment was being processed. This is on the payment network\'s side, not with your card.',
				'action'  => 'Please use the link below to try again. If it happens a second time, a different card will usually go through.',
				'posture' => self::RETRY_FIX_DETAILS,
				'alt'     => true,
			),
		);
	}

	/**
	 * ISO 8583 response codes, used when the processor throws a code we have no specific copy for.
	 * The processor reports these in square brackets in its error detail (e.g. "... [62]").
	 */
	private static function iso_map(): array {
		return array(
			'05' => '9011', '14' => '9069', '51' => '1502', '54' => '1506',
			'57' => '1781', '61' => '9043', '62' => '1556', '74' => '1782',
			'79' => '9051', '82' => '1784', '83' => '9014', 'T5' => '9006',
			'41' => '9014', '43' => '9014', '59' => '9877', '65' => '9845',
			'75' => '9014', '78' => '9006', '04' => '9014', '07' => '9014',
			'12' => '1782', '13' => '9043', '15' => '9069', '30' => '9069',
		);
	}

	/**
	 * The action text used when a code's bespoke wording would contradict the sheet's bucket.
	 * Naming the reason is always safe; only the instruction has to follow the classification.
	 */
	private static function generic_action( string $posture ): string {
		switch ( $posture ) {
			case MPS_Decline_Codes::DO_NOT_RETRY:
				return 'Please do not try this card again — repeated attempts can lock it. Contact your bank, or pay with a different card.';
			case MPS_Decline_Codes::CONTACT_ISSUER:
				return 'Call the number on the back of your card and authorize the purchase, then use the link below to try again.';
			case MPS_Decline_Codes::VERIFY_CARD:
				return 'Use the link below and check the card number, expiry date and security code before trying again.';
		}
		return 'You can complete the order with a different card.';
	}

	/**
	 * Resolve a decline to customer-facing copy. Never returns null — an unknown code gets honest
	 * generic wording plus the alternative payment methods, which is always safe.
	 *
	 * @param string $code Processor decline code, e.g. "1556".
	 * @param string $iso  ISO 8583 code parsed from the detail text, e.g. "62". Optional.
	 */
	public static function resolve( string $code, string $iso = '' ): array {
		$code = trim( $code );
		$iso  = strtoupper( trim( $iso ) );
		$map  = self::map();

		$matched = 'fallback';
		$copy    = null;

		if ( isset( $map[ $code ] ) ) {
			$copy    = $map[ $code ];
			$matched = 'code';
		} else {
			// The sheet says nothing about this code, so reach for the ISO response code.
			$iso_map = self::iso_map();
			if ( '' !== $iso && isset( $iso_map[ $iso ], $map[ $iso_map[ $iso ] ] ) ) {
				$copy    = $map[ $iso_map[ $iso ] ];
				$matched = 'iso:' . $iso;
			}
		}

		/*
		 * Posture. The sheet wins wherever it has ruled on this code. Where it is silent,
		 * classify() would answer RETRY_OTHER for "unknown" exactly as it does for "GREY", so the
		 * copy's own posture is the better information and is used instead.
		 */
		$on_sheet = MPS_Decline_Codes::is_known( $code );
		$posture  = $on_sheet
			? MPS_Decline_Codes::classify( $code )
			: ( $copy['posture'] ?? MPS_Decline_Codes::classify( $code ) );

		if ( ! $copy ) {
			return array(
				'reason'  => 'Your bank declined the transaction and did not tell us the specific reason. This most often means their fraud screening did not recognise the purchase.',
				'action'  => self::generic_action( $posture ),
				'posture' => $posture,
				'alt'     => true,
				'code'    => $code,
				'matched' => $matched,
			);
		}

		// Keep the bespoke reason; swap the action only when it would fight the classification.
		$action = ( ( $copy['posture'] ?? $posture ) === $posture )
			? $copy['action']
			: self::generic_action( $posture );

		return array(
			'reason'  => $copy['reason'],
			'action'  => $action,
			'posture' => $posture,
			// A customer who must not retry needs somewhere else to go, whatever the code said.
			'alt'     => ! empty( $copy['alt'] ) || MPS_Decline_Codes::DO_NOT_RETRY === $posture,
			'code'    => $code,
			'matched' => $matched . ( $on_sheet ? '+sheet' : '' ),
		);
	}

	/** True when the customer must not put the same card in again. */
	public static function is_terminal( string $code, string $iso = '' ): bool {
		return MPS_Decline_Codes::DO_NOT_RETRY === self::resolve( $code, $iso )['posture'];
	}

	/** Short label for the admin dashboard column. */
	public static function posture_label( string $posture ): string {
		switch ( $posture ) {
			case MPS_Decline_Codes::CONTACT_ISSUER: return 'Call issuer';
			case MPS_Decline_Codes::VERIFY_CARD:    return 'Check details';
			case MPS_Decline_Codes::RETRY_OTHER:    return 'Other card';
			case MPS_Decline_Codes::DO_NOT_RETRY:   return 'Do not retry';
		}
		return $posture;
	}
}
