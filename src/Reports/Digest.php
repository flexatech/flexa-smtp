<?php

declare(strict_types=1);

namespace Flexa\Smtp\Reports;

use Flexa\Smtp\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and sends the scheduled email digest (weekly/monthly). The body is a
 * self-contained HTML summary rendered from {@see Stats::summary()}. Recipients
 * come from the `report_recipients` setting, falling back to the site admin.
 */
final class Digest {
	/**
	 * Build the digest for the trailing $days window and send it. Returns the
	 * recipients it went to (empty if there was nothing to send to).
	 *
	 * @return list<string>
	 */
	public static function send( int $days, string $period_label ): array {
		$recipients = self::recipients();
		if ( [] === $recipients ) {
			return [];
		}

		$summary = Stats::summary( $days );
		$subject = sprintf(
			/* translators: 1: site name, 2: period label e.g. "weekly". */
			__( '[%1$s] Your %2$s email report', 'flexa-smtp' ),
			wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			$period_label
		);

		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
		$body    = self::render( $summary, $period_label );

		wp_mail( $recipients, $subject, $body, $headers );

		return $recipients;
	}

	/**
	 * @return list<string>
	 */
	public static function recipients(): array {
		$raw = (string) Settings::get( 'report_recipients' );
		$out = [];
		foreach ( preg_split( '/[,\s]+/', $raw ) ?: [] as $candidate ) {
			$email = sanitize_email( (string) $candidate );
			if ( '' !== $email && is_email( $email ) ) {
				$out[] = $email;
			}
		}

		if ( [] === $out ) {
			$admin = sanitize_email( (string) get_option( 'admin_email' ) );
			if ( '' !== $admin && is_email( $admin ) ) {
				$out[] = $admin;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * @param array<string, mixed> $summary
	 */
	public static function render( array $summary, string $period_label ): string {
		$totals = is_array( $summary['totals'] ?? null ) ? $summary['totals'] : [];
		$range  = is_array( $summary['range'] ?? null ) ? $summary['range'] : [];

		$cards = [
			__( 'Sent', 'flexa-smtp' )       => number_format_i18n( (int) ( $totals['sent'] ?? 0 ) ),
			__( 'Failed', 'flexa-smtp' )     => number_format_i18n( (int) ( $totals['failed'] ?? 0 ) ),
			__( 'Opens', 'flexa-smtp' )      => number_format_i18n( (int) ( $totals['opens'] ?? 0 ) ),
			__( 'Clicks', 'flexa-smtp' )     => number_format_i18n( (int) ( $totals['clicks'] ?? 0 ) ),
			__( 'Open rate', 'flexa-smtp' )  => ( (float) ( $totals['open_rate'] ?? 0 ) ) . '%',
			__( 'Click rate', 'flexa-smtp' ) => ( (float) ( $totals['click_rate'] ?? 0 ) ) . '%',
		];

		$rows = '';
		foreach ( $cards as $label => $value ) {
			$rows .= sprintf(
				'<tr><td style="padding:8px 12px;border-bottom:1px solid #eee;color:#555;">%s</td><td style="padding:8px 12px;border-bottom:1px solid #eee;text-align:right;font-weight:600;color:#111;">%s</td></tr>',
				esc_html( $label ),
				esc_html( (string) $value )
			);
		}

		$links = '';
		foreach ( is_array( $summary['top_links'] ?? null ) ? $summary['top_links'] : [] as $link ) {
			$links .= sprintf(
				'<li style="margin:4px 0;color:#555;"><span style="color:#111;">%s×</span> %s</li>',
				esc_html( number_format_i18n( (int) ( $link['clicks'] ?? 0 ) ) ),
				esc_html( (string) ( $link['url'] ?? '' ) )
			);
		}

		$header = sprintf(
			/* translators: 1: period label, 2: from date, 3: to date. */
			esc_html__( 'Your %1$s email report (%2$s – %3$s)', 'flexa-smtp' ),
			esc_html( $period_label ),
			esc_html( substr( (string) ( $range['from'] ?? '' ), 0, 10 ) ),
			esc_html( substr( (string) ( $range['to'] ?? '' ), 0, 10 ) )
		);

		return sprintf(
			'<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:560px;margin:0 auto;">'
			. '<h2 style="color:#111;font-size:18px;">%s</h2>'
			. '<table style="width:100%%;border-collapse:collapse;border:1px solid #eee;border-radius:8px;">%s</table>'
			. '%s'
			. '</div>',
			$header,
			$rows,
			'' !== $links
				? '<h3 style="color:#111;font-size:15px;margin-top:20px;">' . esc_html__( 'Top links', 'flexa-smtp' ) . '</h3><ul style="padding-left:18px;margin:0;">' . $links . '</ul>'
				: ''
		);
	}
}
