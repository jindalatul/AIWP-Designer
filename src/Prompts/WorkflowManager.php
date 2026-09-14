<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Prompts;

/**
 * Issues and checks workflow IDs.
 *
 * A workflow ID proves the AI has read the plugin's current instructions before
 * changing the website. It is NOT authentication and grants no permission of
 * its own: the request still has to be authorised as a WordPress user.
 */
final class WorkflowManager {

	private const TRANSIENT_PREFIX = 'aiwp_wf_';
	public const  TTL              = 3600;

	/**
	 * @return array{workflow_id:string,expires_at:string}
	 */
	public function start( string $type, int $user_id ): array {
		$id      = wp_generate_uuid4();
		$expires = time() + self::TTL;

		set_transient(
			self::TRANSIENT_PREFIX . $id,
			array(
				'type'    => $type,
				'user'    => $user_id,
				'expires' => $expires,
			),
			self::TTL
		);

		return array(
			'workflow_id' => $id,
			'expires_at'  => gmdate( 'c', $expires ),
		);
	}

	/**
	 * @return array{valid:bool,code:string,message:string}
	 */
	public function check( string $workflow_id, string $expected_type, int $user_id ): array {
		if ( '' === $workflow_id ) {
			return array(
				'valid'   => false,
				'code'    => 'AIWP_WORKFLOW_REQUIRED',
				'message' => 'Call workflow.prepare first and pass its workflow_id with this tool.',
			);
		}

		$record = get_transient( self::TRANSIENT_PREFIX . $workflow_id );
		if ( ! is_array( $record ) ) {
			return array(
				'valid'   => false,
				'code'    => 'AIWP_WORKFLOW_EXPIRED',
				'message' => 'That workflow_id has expired. Call workflow.prepare again.',
			);
		}

		if ( (int) $record['user'] !== $user_id ) {
			return array(
				'valid'   => false,
				'code'    => 'AIWP_PERMISSION_DENIED',
				'message' => 'That workflow_id belongs to a different user.',
			);
		}

		// Both sides through the same renaming, so a workflow prepared under an
		// old name still satisfies a tool that asks under the new one.
		if ( PromptRegistry::canonical( (string) $record['type'] ) !== PromptRegistry::canonical( $expected_type ) ) {
			return array(
				'valid'   => false,
				'code'    => 'AIWP_WORKFLOW_TYPE_MISMATCH',
				'message' => sprintf(
					'That workflow_id was prepared for "%s", but this tool needs a "%s" workflow.',
					(string) $record['type'],
					$expected_type
				),
			);
		}

		return array(
			'valid'   => true,
			'code'    => '',
			'message' => '',
		);
	}

	public function finish( string $workflow_id ): void {
		delete_transient( self::TRANSIENT_PREFIX . $workflow_id );
	}
}
