<?php

use App\Providers\AppServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\KnowledgePlatformServiceProvider;

return [
    AppServiceProvider::class,
    EventServiceProvider::class,
    // Binds the knowledge platform's capability contracts (research / extraction / embedding /
    // reranker) to whichever vendor is configured. See config/knowledge_platform.php.
    KnowledgePlatformServiceProvider::class,
];
