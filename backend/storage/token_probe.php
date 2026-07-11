<?php
use App\Models\User;
$u = User::query()->orderBy('id')->first();
if (! $u) { echo "NO_USER\n"; return; }
// Fresh token for profiling.
$t = $u->createToken('perf-probe')->plainTextToken;
echo "USER={$u->email}\n";
echo "TOKEN={$t}\n";
