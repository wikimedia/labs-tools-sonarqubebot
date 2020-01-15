<?php


namespace App\Tests;

use App\Controller\WebhookController;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class WebhookControllerTests
 * @package App\Tests
 */
class WebhookControllerTests extends TestCase {

	public function testGetRequest() {
		$requestMock = $this->getRequestMock();
		$requestMock->method( 'isMethod' )->willReturn( 'GET' );
		$logger = new NullLogger();
		$this->assertInstanceOf(
			RedirectResponse::class,
			WebhookController::index( $requestMock, $logger )
		);
	}

	private function getRequestMock() {
		return $this->getMockBuilder( Request::class )
			->disableOriginalConstructor()
			->getMock();
	}

}
