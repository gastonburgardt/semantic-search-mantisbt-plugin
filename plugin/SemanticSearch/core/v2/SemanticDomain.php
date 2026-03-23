<?php

final class SemanticEntityType {
	const ISSUE = 'Issue';
	const ISSUENOTE = 'IssueNote';
	const ISSUENOTEFILE = 'IssueNoteFile';
}

final class SemanticPolicyAction {
	const NOTHING = 'Nothing';
	const CREATE_INDEX = 'CreateIndex';
	const UPDATE_INDEX = 'UpdateIndex';
	const DELETE_INDEX = 'DeleteIndex';
}

final class SemanticReviewLevel {
	const NONE = 'NoRevisarNada';
	const ONLY_ME = 'SoloYo';
	const ONLY_CHILDREN = 'SoloMisHijos';
	const ME_AND_CHILDREN = 'YoYMisHijos';
}
