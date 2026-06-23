<?php

/*
 * This file is part of MailSo.
 *
 * (c) 2014 Usenko Timur
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Smail\Mail\Imap\Exceptions;

/**
 * @category MailSo
 * @package Imap
 * @subpackage Exceptions
 */
class ResponseException extends \Smail\Mail\RuntimeException
{
	private $oResponses;

	public function __construct(?\Smail\Mail\Imap\ResponseCollection $oResponses = null, string $sMessage = '', int $iCode = 0, ?\Throwable $oPrevious = null)
	{
		$this->oResponses = $oResponses;
		if (!$sMessage && $response = $this->GetLastResponse()) {
			$sMessage = ($response->OptionalResponse[0] ?? '') . ' ' . $response->HumanReadable;
		}
		parent::__construct($sMessage, $iCode, $oPrevious);
	}

	public function GetResponseStatus() : ?string
	{
		$oItem = $this->GetLastResponse();
		return $oItem && $oItem->IsStatusResponse ? $oItem->StatusOrIndex : null;
	}

	public function GetResponses() : ?\Smail\Mail\Imap\ResponseCollection
	{
		return $this->oResponses;
	}

	public function GetLastResponse() : ?\Smail\Mail\Imap\Response
	{
		return $this->oResponses ? $this->oResponses->getLast() : null;
	}
}
