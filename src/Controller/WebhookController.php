<?php

namespace App\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class WebhookController {

	private const FEEDBACK_URL =
		'https://www.mediawiki.org/wiki/Talk:Continuous_integration/Codehealth_Pipeline';

	private const SONARQUBE_HOST = 'https://sonarcloud.io';

	/**
	 * @param Request $request
	 * @param LoggerInterface $logger
	 * @return RedirectResponse|Response
	 * @throws \Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface
	 * @throws \Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface
	 * @throws \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface
	 * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
	 */
	public static function index( Request $request, LoggerInterface $logger ) {
		if ( $request->isMethod( 'GET' ) ) {
			return new RedirectResponse(
				'https://www.mediawiki.org/wiki/Continuous_integration/Codehealth_Pipeline'
			);
		}

		$inlineCommentProjectWhitelist = explode( '|', $_SERVER['INLINECOMMENTWHITELIST'] ?? [] );

		$hmac = $request->headers->get( 'X-Sonar-Webhook-HMAC-SHA256' );
		$expected_hmac = hash_hmac( 'sha256', $request->getContent(), $_SERVER['SONARQUBE_HMAC'] );
		if ( !$hmac || $hmac !== $expected_hmac ) {
			$logger->error( 'HMAC validation error.' );
			return new Response();
		}
		$analysisJson = json_decode( $request->getContent(), true );
		if ( $analysisJson['branch']['name'] === 'master' ) {
			// Skip commenting on master for now.
			return new Response( 'No comment.' );
		}
		$gerritProject = $analysisJson['properties']['sonar.analysis.gerritProjectName'];
		if ( !$gerritProject ) {
			$logger->error( 'No gerrit project name provided.' );
			return new Response();
		}
		$passedQualityGate = $analysisJson['qualityGate']['status'] === 'OK';
		$successMessage = $passedQualityGate ?
			'✔ Quality gate passed!' :
			'❌ Quality gate failed';
		$detailsMessage = '';
		foreach ( $analysisJson['qualityGate']['conditions']  as $condition ) {
			$humanReadableReason = '';
			$humanReadableMetric = trim( str_replace( 'new', '', str_replace( '_', ' ',
				$condition['metric'] ) ) );
			if ( isset( $condition['value'] ) ) {
				$humanReadableReason = $condition['value'] . ' is ' . strtolower( str_replace( '_',
						' ', $condition['operator'] ) ) . ' ' . $condition['errorThreshold'];
			}
			$detailsMessage .= ( $condition['status'] === 'OK' || $condition['status'] === 'NO_VALUE' ) ?
				"\n* ✔ " . $humanReadableMetric :
				"\n* ❌ " . $humanReadableMetric . ' (' . $humanReadableReason . ')';
		}
		$detailsMessage .= "\n\nReport: " . $analysisJson['branch']['url'];
		if ( !$passedQualityGate ) {
			$detailsMessage .= "\n\nThis patch can still be merged.";
		}
		$detailsMessage .= ' Please give feedback and report false positives at ' . self::FEEDBACK_URL;
		$gerritComment = $successMessage . "\n\n" . $detailsMessage;
		$client = HttpClient::createForBaseUri( 'https://gerrit.wikimedia.org/', [
			'auth_basic' => [ $_SERVER['GERRIT_USERNAME'], $_SERVER['GERRIT_HTTP_PASSWORD'] ]
		] );
		list( $gerritShortId, $gerritRevision ) = explode( '-', $analysisJson['branch']['name'] );
		$url = '/r/a/changes/' . urlencode( $gerritProject ) . '~' . $gerritShortId . '/revisions/' .
			   $gerritRevision . '/review';
		try {
			$params = [
				'json' => [
					'message' => $gerritComment,
					// 'autogenerated' allows filtering out this message via the web UI
					'tag' => 'autogenerated:codehealth',
					'labels' => [
						'Verified' => $passedQualityGate ? 1 : 0
					]
				]
			];
			if ( in_array( $gerritProject, $inlineCommentProjectWhitelist ) ) {
				$comments = self::getInlineComments(
					$analysisJson['project']['key'],
					$analysisJson['branch']['name'],
					$analysisJson['taskId'],
					$logger
				);
				if ( count( $comments ) ) {
					// FIXME: We don't use robot_comments for reasons noted in T217008
					$params['json']['comments'] = $comments;
				}
			}
			$response = $client->request( 'POST', $url,  $params );
			$logger->info( $response->getStatusCode() . ' ' . $response->getContent() );
		} catch ( \Exception $exception ) {
			$logger->error( $exception->getMessage() );
		}
		return new Response();
	}

	/**
	 * @param string $project
	 *   The name of the project in sonarqube.
	 * @param string $branch
	 *   The branch ID, which is the gerrit short change ID plus a dash and a number for the
	 *   revision.
	 * @param string $taskId
	 *   The sonar analysis task ID, used for robot_run_id
	 * @param LoggerInterface $logger
	 * @return array
	 * @throws \Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface
	 * @throws \Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface
	 * @throws \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface
	 * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
	 */
	private static function getInlineComments(
		$project, $branch, $taskId, LoggerInterface $logger
	) :array {
		$client = HttpClient::createForBaseUri( self::SONARQUBE_HOST );
		$robotComments = [];
		try {
			$response = $client->request( 'GET', 'api/issues/search', [
				'query' => [
					'projects' => $project,
					'branch' => $branch
				]
			] )->getContent();
			$responseDecoded = json_decode( $response, true );
			if ( $responseDecoded['total'] === 0 ) {
				return [];
			}
			$issues = $responseDecoded['issues'];
			foreach ( $issues as $issue ) {
				$filename = explode( ':', $issue['component'] )[1];
				$inlineComment = [];
				$textRange = $issue['textRange'];
				if ( $textRange['startLine'] !== $textRange['endLine'] ) {
					$inlineComment['range'] = [
						'start_line' => $textRange['startLine'],
						'start_character' => $textRange['startOffset'],
						'end_line' => $textRange['endLine'],
						'end_character' => $textRange['endOffset']
					];
				} else {
					$inlineComment['line'] = $issue['line'];
				}
				$inlineComment['message'] = $issue['message'];
				$url = sprintf(
					'%s/project/issues?branch=%s&id=%s&issues=%s&open=%s',
					self::SONARQUBE_HOST,
					$branch,
					$project,
					$issue['key'],
					$issue['key']
				);
				$inlineComment['url'] = $url;
				$inlineComment['message'] .= "\n\n" . 'View details: ' . $url;
				$inlineComment['robot_id'] = 'sonarqubebot';
				$inlineComment['robot_run_id'] = $taskId;
				$inlineComment['properties'] = [ 'rule' => $issue['rule'], 'severity' => $issue['severity'] ];
				$robotComments[$filename][] = $inlineComment;
			}
		} catch ( \Exception $exception ) {
			$logger->error( $exception->getMessage() );
		}
		return $robotComments;
	}
}
