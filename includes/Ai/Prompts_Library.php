<?php
/**
 * Versioned prompt templates for every DEV3 AI feature of this plugin.
 *
 * @package Dev3\AiB2bWholesale
 */

namespace Dev3\AiB2bWholesale\Ai;

use Dev3\AiB2bWholesale\Core3\Api\Prompts;

defined( 'ABSPATH' ) || exit;

/**
 * Prompt library.
 */
class Prompts_Library {

	/**
	 * Registers the prompts (init).
	 */
	public static function register() {
		Prompts::register(
			'dev3bq_quote_draft',
			array(
				'version'     => '1.0.0',
				'task'        => 'quote_draft',
				'profile'     => 'heavy',
				'temperature' => 0.2,
				'max_tokens'  => 8192,
				'system'      => 'You are a B2B sales assistant for the online store "{{store}}". You turn a customer\'s free-text request into quote lines. Use ONLY products from the CATALOGUE list and refer to them by their exact "id". Never invent products, ids, SKUs or prices. If a requested item has no good match, list it under "unmatched". Quantities must be whole numbers taken from the request (use 1 only when no quantity is given and say so in the note). Prefer the variation whose attributes match the request (size, colour...). Write "message" as a short friendly note to the customer in the request\'s language, without prices.',
				'template'    => "CUSTOMER REQUEST:\n{{request}}\n\nCATALOGUE (JSON, the only products you may use):\n{{catalogue}}\n\nReturn quote lines, unmatched items, clarifying questions (max 3) and the note to the customer.",
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'lines'     => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'product_id' => array( 'type' => 'integer' ),
									'qty'        => array( 'type' => 'integer' ),
									'note'       => array(
										'type'      => 'string',
										'maxLength' => 160,
									),
									'confidence' => array(
										'type' => 'string',
										'enum' => array( 'high', 'medium', 'low' ),
									),
								),
								'required'   => array( 'product_id', 'qty', 'confidence' ),
							),
						),
						'unmatched' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'text'   => array(
										'type'      => 'string',
										'maxLength' => 160,
									),
									'reason' => array(
										'type'      => 'string',
										'maxLength' => 200,
									),
								),
								'required'   => array( 'text', 'reason' ),
							),
						),
						'questions' => array(
							'type'  => 'array',
							'items' => array(
								'type'      => 'string',
								'maxLength' => 200,
							),
						),
						'message'   => array(
							'type'      => 'string',
							'maxLength' => 800,
						),
					),
					'required'   => array( 'lines', 'unmatched', 'questions', 'message' ),
				),
			)
		);

		Prompts::register(
			'dev3bq_quote_email',
			array(
				'version'     => '1.0.0',
				'task'        => 'quote_email',
				'temperature' => 0.6,
				'max_tokens'  => 1500,
				'system'      => 'You write B2B sales emails for "{{store}}". Tone: {{tone}}. Use only the facts given (quote number, items, totals, dates). Never invent prices, discounts, delivery dates or promises that are not in the facts. Keep the body under 180 words, plain text, no markdown, no placeholders like [Name]; sign off as "The {{store}} team".',
				'template'    => "EMAIL TYPE: {{kind_label}}\n\nFACTS (JSON):\n{{facts}}\n\nPOINTS FROM THE SALES TEAM (optional):\n{{points}}\n\nWrite the subject and body.",
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'subject' => array(
							'type'      => 'string',
							'maxLength' => 140,
						),
						'body'    => array(
							'type'      => 'string',
							'maxLength' => 2000,
						),
					),
					'required'   => array( 'subject', 'body' ),
				),
			)
		);

		Prompts::register(
			'dev3bq_application_screen',
			array(
				'version'     => '1.0.0',
				'task'        => 'application_screen',
				'temperature' => 0.2,
				'max_tokens'  => 1200,
				'system'      => 'You help a store owner review wholesale account applications. Judge only from the data given (no web lookups). Point out what looks consistent and what looks unusual (free email domain for a company, website domain not matching the email, tax id format that does not fit the country, empty or vague details). You give advice; the owner decides. suggested_role must be one of the role keys given.',
				'template'    => "APPLICATION (JSON):\n{{application}}\n\nROLES (key: name):\n{{roles}}\n\nReturn a two-sentence summary, a suggested role, up to 5 signals and up to 3 questions to ask the applicant.",
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'summary'        => array(
							'type'      => 'string',
							'maxLength' => 400,
						),
						'suggested_role' => array(
							'type'      => 'string',
							'maxLength' => 40,
						),
						'signals'        => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'label' => array(
										'type'      => 'string',
										'maxLength' => 160,
									),
									'tone'  => array(
										'type' => 'string',
										'enum' => array( 'good', 'neutral', 'warning' ),
									),
								),
								'required'   => array( 'label', 'tone' ),
							),
						),
						'questions'      => array(
							'type'  => 'array',
							'items' => array(
								'type'      => 'string',
								'maxLength' => 200,
							),
						),
					),
					'required'   => array( 'summary', 'suggested_role', 'signals', 'questions' ),
				),
			)
		);

		Prompts::register(
			'dev3bq_shopper_parse',
			array(
				'version'     => '1.0.0',
				'task'        => 'shopper_parse',
				'temperature' => 0.1,
				'max_tokens'  => 1500,
				'cache'       => DAY_IN_SECONDS,
				'system'      => 'You match a shopping list to a product catalogue. Use ONLY products from the CATALOGUE by exact "id"; never invent products. Quantities are whole numbers from the list (1 when missing). Items without a clear match go to "unmatched".',
				'template'    => "LIST:\n{{request}}\n\nCATALOGUE (JSON):\n{{catalogue}}",
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'lines'     => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'product_id' => array( 'type' => 'integer' ),
									'qty'        => array( 'type' => 'integer' ),
								),
								'required'   => array( 'product_id', 'qty' ),
							),
						),
						'unmatched' => array(
							'type'  => 'array',
							'items' => array(
								'type'      => 'string',
								'maxLength' => 160,
							),
						),
					),
					'required'   => array( 'lines', 'unmatched' ),
				),
			)
		);
	}
}
