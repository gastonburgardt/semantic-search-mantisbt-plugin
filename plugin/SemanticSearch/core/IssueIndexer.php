<?php

require_once __DIR__ . '/v2/SemanticDomain.php';
require_once __DIR__ . '/v2/SemanticPolicyRepository.php';
require_once __DIR__ . '/v2/SemanticIssueInventoryRepository.php';
require_once __DIR__ . '/v2/SemanticV2Engine.php';

/**
 * V2 entrypoint used by hooks/pages.
 */
class IssueIndexer extends SemanticV2Engine {
}
