<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Services/WorkflowService.php';

use App\Services\WorkflowService;

$workflow = new WorkflowService();
$sequence = ['draft'];
$sequence[] = $workflow->transition(end($sequence), 'submit');
$sequence[] = $workflow->transition(end($sequence), 'approve');
$sequence[] = $workflow->transition(end($sequence), 'approve');

if ($sequence !== ['draft', 'pending_review', 'pending_approval', 'approved']) {
    fwrite(STDERR, 'FAIL workflow sequence: ' . json_encode($sequence) . PHP_EOL);
    exit(1);
}

try {
    $workflow->transition('approved', 'approve');
    fwrite(STDERR, "FAIL approved record was mutable\n");
    exit(1);
} catch (DomainException) {
    echo "WorkflowServiceTest passed\n";
}

