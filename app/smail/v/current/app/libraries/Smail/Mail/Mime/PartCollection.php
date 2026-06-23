<?php

/*
 * This file is part of MailSo.
 *
 * (c) 2014 Usenko Timur
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Smail\Mail\Mime;

/**
 * @category MailSo
 * @package Mime
 */
class PartCollection extends \Smail\Mail\Base\Collection
{
	protected string $sBoundary = '';

	public function append($oPart, bool $bToTop = false) : void
	{
		assert($oPart instanceof Part);
		parent::append($oPart, $bToTop);
	}

	private static $increment = 0;
	public function Boundary() : string
	{
		if (!$this->sBoundary) {
			$this->sBoundary =
				\Smail\Mail\Config::$BoundaryPrefix
				. \Smail\Engine\UUID::generate()
				. '-' . ++self::$increment;

		}
		return $this->sBoundary;
	}

	public function SetBoundary(string $sBoundary) : void
	{
		$this->sBoundary = $sBoundary;
	}

	/**
	 * @return resource|bool|null
	 */
	public function ToStream()
	{
		if ($this->count() && $this->sBoundary) {
			$aResult = array();
			foreach ($this as $oPart) {
				$aResult[] = "\r\n--{$this->sBoundary}\r\n";
				$aResult[] = $oPart->ToStream();
			}
			$aResult[] = "\r\n--{$this->sBoundary}--\r\n";
			return \Smail\Mail\Base\StreamWrappers\SubStreams::CreateStream($aResult);
		}
		return null;
	}
}
