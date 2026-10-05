<?php
require __DIR__ . '/runtime.php';
final class Pixel_Crash_Executor implements WP_Seed_Pixel_Job_Executor {
    private $boundary;
    public function __construct($boundary) { $this->boundary = $boundary; }
    public function reconcile(array $item, array $policy) { return (new WP_Seed_Pixel_Simulated_Executor())->reconcile($item, $policy); }
    public function execute($stage, array $item, array $policy) {
        if ($stage === $this->boundary) {
            // Termination bypasses finally, simulating request/process death after durable intent.
            echo 'Durable boundary reached. PID=' . getmypid() . "\n"; flush();
            if (getenv('PIXEL_QA_HOLD_LOCK') || ($GLOBALS['argv'][3] ?? '') === 'hold') { sleep(30); }
            exit(73);
        }
        return (new WP_Seed_Pixel_Simulated_Executor())->execute($stage, $item, $policy);
    }
}
$result = WP_Seed_Pixel_Jobs::step((int) ($argv[1] ?? 0), new Pixel_Crash_Executor($argv[2] ?? 'switch_intent'));
if (is_wp_error($result)) { echo $result->get_error_code(); exit(1); }
echo wp_json_encode($result);
