<?php
$n = DB::table('personal_access_tokens')->where('name', 'perf-probe')->delete();
echo "Revoked {$n} perf-probe token(s)\n";
