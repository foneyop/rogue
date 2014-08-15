<?php
/**
 * Format locale based strings, such as dates and money
 *
 * @author cory
 */
class RogueLocale
{
	private static $_locale;

	public static function setLocale($locale)
	{
		self::$_locale = $locale;
	}

	public static function number($value)
	{
		return number_format($value);
	}


	public static function money($value)
	{
		return money_format('%i', $value);
	}

	public static function date($timestamp)
	{
		return date('D, M j Y', $timestamp);
	}
}
?>
