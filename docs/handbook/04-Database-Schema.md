# Database Schema Reference

Generated directly from the live `laravel` database (MySQL 8 / MariaDB local). Every table, every column, every foreign key and index.

Row counts are the engine's own approximation and are there to show you which tables actually carry data - treat them as an order of magnitude, not an exact figure.

## Table index

| # | Table | Approx. rows |
|---|---|---|
| 1 | [`action_catalog`](#actioncatalog) | 94 |
| 2 | [`app_settings`](#appsettings) | 1 |
| 3 | [`cache`](#cache) | 561 |
| 4 | [`cache_locks`](#cachelocks) | 1 |
| 5 | [`capability_promotions`](#capabilitypromotions) | 3 |
| 6 | [`capture_friction`](#capturefriction) | 15 |
| 7 | [`complaints`](#complaints) | 7 |
| 8 | [`complaint_events`](#complaintevents) | 14 |
| 9 | [`component_catalog`](#componentcatalog) | 132 |
| 10 | [`component_events`](#componentevents) | 5524 |
| 11 | [`concept_bridge_labels`](#conceptbridgelabels) | 210 |
| 12 | [`concept_bridge_samples`](#conceptbridgesamples) | 210 |
| 13 | [`contact_reminders`](#contactreminders) | 0 |
| 14 | [`contracts`](#contracts) | 38659 |
| 15 | [`contract_mileage_readings`](#contractmileagereadings) | 32 |
| 16 | [`contract_oil_decisions`](#contractoildecisions) | 93 |
| 17 | [`cost_adjustments`](#costadjustments) | 0 |
| 18 | [`customers`](#customers) | 17216 |
| 19 | [`damage_catalog`](#damagecatalog) | 27 |
| 20 | [`domain_events`](#domainevents) | 54 |
| 21 | [`drivers`](#drivers) | 0 |
| 22 | [`driver_observations`](#driverobservations) | 5 |
| 23 | [`evidence_links`](#evidencelinks) | 0 |
| 24 | [`failed_jobs`](#failedjobs) | 0 |
| 25 | [`fault_catalog`](#faultcatalog) | 64 |
| 26 | [`fault_causes`](#faultcauses) | 474 |
| 27 | [`fault_concept_actions`](#faultconceptactions) | 448 |
| 28 | [`fault_recurrence_pairs`](#faultrecurrencepairs) | 13276 |
| 29 | [`finding_keywords`](#findingkeywords) | 107 |
| 30 | [`garage_invoice_submissions`](#garageinvoicesubmissions) | 7 |
| 31 | [`garage_recommendation_decisions`](#garagerecommendationdecisions) | 9 |
| 32 | [`garage_routing_rules`](#garageroutingrules) | 0 |
| 33 | [`inspection_records`](#inspectionrecords) | 206 |
| 34 | [`inspection_schedules`](#inspectionschedules) | 0 |
| 35 | [`inspection_types`](#inspectiontypes) | 5 |
| 36 | [`inspector_pad_flags`](#inspectorpadflags) | 0 |
| 37 | [`intelligence_rebuild_runs`](#intelligencerebuildruns) | 4 |
| 38 | [`invoices`](#invoices) | 22982 |
| 39 | [`invoice_items`](#invoiceitems) | 0 |
| 40 | [`jobs`](#jobs) | 0 |
| 41 | [`job_batches`](#jobbatches) | 0 |
| 42 | [`keyword_enrichment_runs`](#keywordenrichmentruns) | 4 |
| 43 | [`keyword_profiles`](#keywordprofiles) | 105 |
| 44 | [`keyword_terms`](#keywordterms) | 2319 |
| 45 | [`knowledge_chunks`](#knowledgechunks) | 0 |
| 46 | [`knowledge_documents`](#knowledgedocuments) | 0 |
| 47 | [`knowledge_sources`](#knowledgesources) | 17 |
| 48 | [`kpi_snapshots`](#kpisnapshots) | 1 |
| 49 | [`logistics_tasks`](#logisticstasks) | 35 |
| 50 | [`logistics_task_events`](#logisticstaskevents) | 88 |
| 51 | [`maintenances`](#maintenances) | 26913 |
| 52 | [`maintenance_checkpoints`](#maintenancecheckpoints) | 4 |
| 53 | [`maintenance_checkpoint_reminders`](#maintenancecheckpointreminders) | 234 |
| 54 | [`maintenance_handovers`](#maintenancehandovers) | 2 |
| 55 | [`maintenance_handover_comparisons`](#maintenancehandovercomparisons) | 0 |
| 56 | [`maintenance_incidents`](#maintenanceincidents) | 0 |
| 57 | [`maintenance_invoices`](#maintenanceinvoices) | 0 |
| 58 | [`maintenance_items`](#maintenanceitems) | 0 |
| 59 | [`maintenance_line_items`](#maintenancelineitems) | 88 |
| 60 | [`maintenance_media`](#maintenancemedia) | 27 |
| 61 | [`maintenance_reasons`](#maintenancereasons) | 39 |
| 62 | [`maintenance_required_parts`](#maintenancerequiredparts) | 6 |
| 63 | [`maintenance_responsibles`](#maintenanceresponsibles) | 0 |
| 64 | [`maintenance_signatures`](#maintenancesignatures) | 52094 |
| 65 | [`maintenance_swaps`](#maintenanceswaps) | 0 |
| 66 | [`maintenance_tasks`](#maintenancetasks) | 158 |
| 67 | [`maintenance_task_actions`](#maintenancetaskactions) | 0 |
| 68 | [`maintenance_task_assignments`](#maintenancetaskassignments) | 38 |
| 69 | [`maintenance_task_locations`](#maintenancetasklocations) | 0 |
| 70 | [`maintenance_temporary_releases`](#maintenancetemporaryreleases) | 3 |
| 71 | [`maintenance_tombstones`](#maintenancetombstones) | 0 |
| 72 | [`maintenance_watchers`](#maintenancewatchers) | 4 |
| 73 | [`media`](#media) | 0 |
| 74 | [`migrations`](#migrations) | 264 |
| 75 | [`mileage_overrides`](#mileageoverrides) | 0 |
| 76 | [`model_has_permissions`](#modelhaspermissions) | 0 |
| 77 | [`model_has_roles`](#modelhasroles) | 16 |
| 78 | [`notifications`](#notifications) | 19907 |
| 79 | [`odometer_block_events`](#odometerblockevents) | 7 |
| 80 | [`odometer_change_requests`](#odometerchangerequests) | 5 |
| 81 | [`oil_recall_tasks`](#oilrecalltasks) | 22 |
| 82 | [`ontology_edges`](#ontologyedges) | 2066 |
| 83 | [`ontology_feedback`](#ontologyfeedback) | 0 |
| 84 | [`ontology_nodes`](#ontologynodes) | 1465 |
| 85 | [`part_investigations`](#partinvestigations) | 0 |
| 86 | [`part_invoices`](#partinvoices) | 0 |
| 87 | [`part_purchases`](#partpurchases) | 3271 |
| 88 | [`part_requests`](#partrequests) | 19 |
| 89 | [`part_request_required_part`](#partrequestrequiredpart) | 4 |
| 90 | [`part_returns`](#partreturns) | 0 |
| 91 | [`part_rfqs`](#partrfqs) | 0 |
| 92 | [`password_reset_tokens`](#passwordresettokens) | 0 |
| 93 | [`payments`](#payments) | 0 |
| 94 | [`payment_allocations`](#paymentallocations) | 0 |
| 95 | [`permissions`](#permissions) | 40 |
| 96 | [`personal_access_tokens`](#personalaccesstokens) | 148 |
| 97 | [`plate_assignments`](#plateassignments) | 438 |
| 98 | [`plate_codes`](#platecodes) | 287 |
| 99 | [`policy_override_audits`](#policyoverrideaudits) | 0 |
| 100 | [`recommendations`](#recommendations) | 0 |
| 101 | [`recommendation_events`](#recommendationevents) | 0 |
| 102 | [`recurring_fault_reviews`](#recurringfaultreviews) | 39 |
| 103 | [`repair_inspections`](#repairinspections) | 34 |
| 104 | [`repair_visits`](#repairvisits) | 9432 |
| 105 | [`resolved_transfer_flags`](#resolvedtransferflags) | 0 |
| 106 | [`review_reminders`](#reviewreminders) | 1 |
| 107 | [`rfq_lines`](#rfqlines) | 0 |
| 108 | [`roles`](#roles) | 10 |
| 109 | [`role_has_permissions`](#rolehaspermissions) | 257 |
| 110 | [`service_catalog`](#servicecatalog) | 19 |
| 111 | [`service_due_snoozes`](#serviceduesnoozes) | 0 |
| 112 | [`service_records`](#servicerecords) | 0 |
| 113 | [`service_reminders`](#servicereminders) | 572 |
| 114 | [`sessions`](#sessions) | 3 |
| 115 | [`simulation_events`](#simulationevents) | 0 |
| 116 | [`supplier_payments`](#supplierpayments) | 0 |
| 117 | [`supplier_quotes`](#supplierquotes) | 0 |
| 118 | [`sync_changes`](#syncchanges) | 6993 |
| 119 | [`sync_corrections`](#synccorrections) | 33 |
| 120 | [`sync_runs`](#syncruns) | 204 |
| 121 | [`traceability_snapshots`](#traceabilitysnapshots) | 0 |
| 122 | [`users`](#users) | 14 |
| 123 | [`user_activity_events`](#useractivityevents) | 4981 |
| 124 | [`vehicles`](#vehicles) | 442 |
| 125 | [`vehicle_components`](#vehiclecomponents) | 3298 |
| 126 | [`vehicle_expenses`](#vehicleexpenses) | 27686 |
| 127 | [`vehicle_garage_locations`](#vehiclegaragelocations) | 4 |
| 128 | [`vehicle_locations`](#vehiclelocations) | 50 |
| 129 | [`vehicle_location_groups`](#vehiclelocationgroups) | 6 |
| 130 | [`vehicle_log_events`](#vehiclelogevents) | 6567 |
| 131 | [`vehicle_registrations`](#vehicleregistrations) | 544 |
| 132 | [`vendors`](#vendors) | 463 |
| 133 | [`warranties`](#warranties) | 0 |
| 134 | [`warranty_claims`](#warrantyclaims) | 0 |

Total: **134 tables**.

---

## Tables

### `action_catalog`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `slug` | varchar(96) | NO |  | unique |
| `verb` | varchar(32) | NO |  | indexed |
| `target` | varchar(96) | NO |  |  |
| `label` | varchar(255) | NO |  |  |
| `label_ar` | varchar(255) | YES | `NULL` |  |
| `category_key` | varchar(32) | YES | `NULL` | indexed |
| `compatible_systems` | longtext | YES | `NULL` |  |
| `requires_part` | tinyint(1) | NO | `0` |  |
| `is_verification` | tinyint(1) | NO | `0` | indexed |
| `default_labor_hours` | decimal(5,2) | YES | `NULL` |  |
| `required_skill` | varchar(48) | YES | `NULL` |  |
| `ontology_repair_node_id` | bigint(20) unsigned | YES | `NULL` |  |
| `is_active` | tinyint(1) | NO | `1` |  |
| `sort_order` | int(10) unsigned | NO | `0` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `category_key,is_active` · `is_verification` · `slug` *(unique)* · `verb,target`

### `app_settings`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `key` | varchar(255) | NO |  | unique |
| `value` | longtext | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `key` *(unique)*

### `cache`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `key` | varchar(255) | NO |  | PK |
| `value` | mediumtext | NO |  |  |
| `expiration` | int(11) | NO |  | indexed |

**Indexes:** `expiration`

### `cache_locks`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `key` | varchar(255) | NO |  | PK |
| `owner` | varchar(255) | NO |  |  |
| `expiration` | int(11) | NO |  | indexed |

**Indexes:** `expiration`

### `capability_promotions`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `capability_id` | varchar(64) | NO |  | indexed |
| `from_basis` | varchar(20) | NO |  |  |
| `to_basis` | varchar(20) | NO |  |  |
| `proxy_model_version` | varchar(120) | YES | `NULL` |  |
| `measured_model_version` | varchar(120) | YES | `NULL` |  |
| `promoted` | tinyint(1) | NO |  |  |
| `reason` | varchar(500) | NO |  |  |
| `decision_rule` | varchar(255) | YES | `NULL` |  |
| `proxy_metrics` | longtext | YES | `NULL` |  |
| `measured_metrics` | longtext | YES | `NULL` |  |
| `operating_point` | longtext | YES | `NULL` |  |
| `evidence_count` | int(10) unsigned | NO | `0` |  |
| `evidence_threshold` | int(10) unsigned | NO | `0` |  |
| `capability_version` | varchar(20) | YES | `NULL` |  |
| `query_layer_version` | varchar(20) | YES | `NULL` |  |
| `dataset_version` | varchar(120) | YES | `NULL` |  |
| `backtest_version` | varchar(20) | YES | `NULL` |  |
| `decided_at` | timestamp | NO | `current_timestamp()` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `capability_id` · `capability_id,promoted,decided_at`

### `capture_friction`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `step` | varchar(48) | NO |  | indexed |
| `session_id` | char(36) | YES | `NULL` | indexed |
| `status` | varchar(16) | NO | `'completed'` |  |
| `last_step` | tinyint(3) unsigned | YES | `NULL` |  |
| `maintenance_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `maintenance_task_id` | bigint(20) unsigned | YES | `NULL` |  |
| `user_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `duration_ms` | int(10) unsigned | YES | `NULL` |  |
| `fields_offered` | smallint(5) unsigned | NO | `0` |  |
| `fields_filled` | smallint(5) unsigned | NO | `0` |  |
| `skipped_fields` | longtext | YES | `NULL` |  |
| `was_corrected` | tinyint(1) | NO | `0` |  |
| `reopened_count` | int(10) unsigned | NO | `0` |  |
| `occurred_at` | timestamp | NO | `current_timestamp()` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `maintenance_id` · `session_id` · `step,occurred_at` · `step,status` · `user_id`

### `complaints`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `vehicle_id` | bigint(20) unsigned | NO |  | indexed |
| `customer_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `contract_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `source` | varchar(20) | NO | `'ops'` |  |
| `status` | varchar(20) | NO | `'new'` | indexed |
| `severity` | varchar(20) | YES | `NULL` |  |
| `description` | text | NO |  |  |
| `decision` | varchar(30) | YES | `NULL` |  |
| `customer_name` | varchar(255) | YES | `NULL` |  |
| `customer_phone` | varchar(40) | YES | `NULL` |  |
| `contract_no` | varchar(60) | YES | `NULL` |  |
| `maintenance_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `legacy_ticket_id` | bigint(20) unsigned | YES | `NULL` | unique |
| `created_by` | bigint(20) unsigned | YES | `NULL` |  |
| `assigned_to` | bigint(20) unsigned | YES | `NULL` | indexed |
| `resolved_at` | timestamp | YES | `NULL` |  |
| `closed_at` | timestamp | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` | indexed |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `assigned_to` · `contract_id` · `created_at` · `customer_id` · `legacy_ticket_id` *(unique)* · `maintenance_id` · `status` · `vehicle_id`

### `complaint_events`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `complaint_id` | bigint(20) unsigned | NO |  | indexed |
| `event_type` | varchar(40) | NO |  |  |
| `notes` | text | YES | `NULL` |  |
| `meta` | longtext | YES | `NULL` |  |
| `created_by` | bigint(20) unsigned | YES | `NULL` |  |
| `created_by_name` | varchar(255) | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `complaint_id,created_at` · `complaint_id`

### `component_catalog`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `slug` | varchar(120) | NO |  | unique |
| `name` | varchar(120) | NO |  |  |
| `name_ar` | varchar(160) | YES | `NULL` |  |
| `aliases` | longtext | YES | `NULL` |  |
| `identity_aliases` | longtext | YES | `NULL` |  |
| `category_key` | varchar(40) | NO |  | indexed |
| `action_target` | varchar(96) | YES | `NULL` | unique |
| `tracking_mode` | varchar(20) | NO |  | indexed |
| `default_part_number` | varchar(80) | YES | `NULL` |  |
| `default_warranty_months` | smallint(5) unsigned | YES | `NULL` |  |
| `default_warranty_km` | int(10) unsigned | YES | `NULL` |  |
| `expected_life_km` | int(10) unsigned | YES | `NULL` |  |
| `expected_life_months` | smallint(5) unsigned | YES | `NULL` |  |
| `position_scheme` | varchar(20) | YES | `NULL` |  |
| `is_active` | tinyint(1) | NO | `1` |  |
| `edited_in_app` | tinyint(1) | NO | `0` |  |
| `edited_at` | timestamp | YES | `NULL` |  |
| `edited_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `edited_by_name` | varchar(255) | YES | `NULL` |  |
| `notes` | text | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `edited_by` → `users.id`

**Indexes:** `action_target` *(unique)* · `category_key` · `edited_by` · `slug` *(unique)* · `tracking_mode`

### `component_events`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `vehicle_component_id` | bigint(20) unsigned | NO |  | indexed |
| `event` | varchar(30) | NO |  | indexed |
| `from_vehicle_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `to_vehicle_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `odometer` | int(10) unsigned | YES | `NULL` |  |
| `maintenance_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `maintenance_task_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `actor_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `actor_name` | varchar(120) | YES | `NULL` |  |
| `at` | datetime | NO |  |  |
| `note` | text | YES | `NULL` |  |
| `meta` | longtext | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `maintenance_task_id` → `maintenance_tasks.id` · `maintenance_id` → `maintenances.id` · `from_vehicle_id` → `vehicles.id` · `vehicle_component_id` → `vehicle_components.id` · `actor_id` → `users.id` · `to_vehicle_id` → `vehicles.id`

**Indexes:** `actor_id` · `event` · `from_vehicle_id,at` · `maintenance_id` · `maintenance_task_id` · `to_vehicle_id,at` · `vehicle_component_id,at`

### `concept_bridge_labels`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `concept_bridge_sample_id` | bigint(20) unsigned | NO |  | indexed |
| `source` | varchar(10) | NO |  | indexed |
| `user_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `labeller` | varchar(60) | YES | `NULL` |  |
| `segment_type` | varchar(20) | YES | `NULL` |  |
| `verdict` | varchar(20) | YES | `NULL` |  |
| `evidence_quality` | varchar(10) | YES | `NULL` |  |
| `valid_concepts` | text | YES | `NULL` |  |
| `invalid_concepts` | text | YES | `NULL` |  |
| `missing_concepts` | text | YES | `NULL` |  |
| `notes` | text | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `user_id` → `users.id` · `concept_bridge_sample_id` → `concept_bridge_samples.id`

**Indexes:** `concept_bridge_sample_id,source,user_id` *(unique)* · `source,verdict` · `user_id`

### `concept_bridge_samples`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `sample_set` | varchar(32) | NO | `'v2'` | indexed |
| `row_no` | int(10) unsigned | NO |  |  |
| `stratum` | varchar(32) | NO |  | indexed |
| `human_review` | tinyint(1) | NO | `0` | indexed |
| `maintenance_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `v1_signature` | varchar(32) | YES | `NULL` |  |
| `source_field` | varchar(32) | YES | `NULL` |  |
| `segment_text` | text | NO |  |  |
| `pred1_concept` | varchar(120) | YES | `NULL` |  |
| `pred1_score` | tinyint(3) unsigned | YES | `NULL` |  |
| `pred1_primary_stage` | varchar(20) | YES | `NULL` |  |
| `pred1_all_stages` | varchar(60) | YES | `NULL` |  |
| `pred1_matched_term` | varchar(160) | YES | `NULL` |  |
| `pred2_concept` | varchar(120) | YES | `NULL` |  |
| `pred2_score` | tinyint(3) unsigned | YES | `NULL` |  |
| `pred3_concept` | varchar(120) | YES | `NULL` |  |
| `pred3_score` | tinyint(3) unsigned | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `human_review` · `maintenance_id` · `sample_set` · `sample_set,row_no` *(unique)* · `stratum`

### `contact_reminders`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `vendor_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `subject` | varchar(255) | NO |  |  |
| `body` | text | YES | `NULL` |  |
| `due_at` | timestamp | YES | `NULL` | indexed |
| `status` | varchar(12) | NO | `'open'` | indexed |
| `invoice_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `maintenance_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `created_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `assigned_to` | bigint(20) unsigned | YES | `NULL` | indexed |
| `completed_at` | timestamp | YES | `NULL` |  |
| `completed_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `vendor_id` → `vendors.id` · `assigned_to` → `users.id` · `maintenance_id` → `maintenances.id` · `invoice_id` → `invoices.id` · `created_by` → `users.id` · `completed_by` → `users.id`

**Indexes:** `assigned_to` · `completed_by` · `created_by` · `due_at` · `invoice_id` · `maintenance_id` · `status` · `vendor_id`

### `contracts`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `contract_no` | varchar(255) | YES | `NULL` | indexed |
| `contract_type` | varchar(255) | YES | `NULL` | indexed |
| `state` | enum('open','closed') | NO | `'open'` | indexed |
| `vehicle_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `car_serial` | bigint(20) unsigned | YES | `NULL` | indexed |
| `customer_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `parent_contract_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `exchange_linked_at` | timestamp | YES | `NULL` |  |
| `exchange_linked_by` | varchar(255) | YES | `NULL` |  |
| `carried_balance` | decimal(12,2) | YES | `NULL` |  |
| `day_price` | decimal(12,2) | YES | `NULL` |  |
| `week_price` | decimal(12,2) | YES | `NULL` |  |
| `month_price` | decimal(12,2) | YES | `NULL` |  |
| `hour_price` | decimal(12,2) | YES | `NULL` |  |
| `year_price` | decimal(12,2) | YES | `NULL` |  |
| `out_date` | date | YES | `NULL` |  |
| `out_time` | varchar(255) | YES | `NULL` |  |
| `out_milage` | int(10) unsigned | YES | `NULL` |  |
| `out_fuel` | varchar(255) | YES | `NULL` |  |
| `opened_by` | varchar(255) | YES | `NULL` |  |
| `in_date` | date | YES | `NULL` |  |
| `in_time` | varchar(255) | YES | `NULL` |  |
| `in_milage` | int(10) unsigned | YES | `NULL` |  |
| `in_fuel` | varchar(255) | YES | `NULL` |  |
| `closed_by` | varchar(255) | YES | `NULL` |  |
| `days` | int(11) | YES | `NULL` |  |
| `km` | int(11) | YES | `NULL` |  |
| `rents_debit` | decimal(12,2) | YES | `NULL` |  |
| `breachs_debit` | decimal(12,2) | YES | `NULL` |  |
| `salik_debit` | decimal(12,2) | YES | `NULL` |  |
| `damages_debit` | decimal(12,2) | YES | `NULL` |  |
| `extra_charges_debit` | decimal(12,2) | YES | `NULL` |  |
| `co_driver_debit` | decimal(12,2) | YES | `NULL` |  |
| `km_debit` | decimal(12,2) | YES | `NULL` |  |
| `fuel_debit` | decimal(12,2) | YES | `NULL` |  |
| `gps_debit` | decimal(12,2) | YES | `NULL` |  |
| `cdw_debit` | decimal(12,2) | YES | `NULL` |  |
| `extra_driver_debit` | decimal(12,2) | YES | `NULL` |  |
| `vat_debit` | decimal(12,2) | YES | `NULL` |  |
| `deposit_debit` | decimal(12,2) | YES | `NULL` |  |
| `rents_credit` | decimal(12,2) | YES | `NULL` |  |
| `breachs_credit` | decimal(12,2) | YES | `NULL` |  |
| `salik_credit` | decimal(12,2) | YES | `NULL` |  |
| `damages_credit` | decimal(12,2) | YES | `NULL` |  |
| `extra_charges_credit` | decimal(12,2) | YES | `NULL` |  |
| `co_driver_credit` | decimal(12,2) | YES | `NULL` |  |
| `km_credit` | decimal(12,2) | YES | `NULL` |  |
| `fuel_credit` | decimal(12,2) | YES | `NULL` |  |
| `gps_credit` | decimal(12,2) | YES | `NULL` |  |
| `cdw_credit` | decimal(12,2) | YES | `NULL` |  |
| `extra_driver_credit` | decimal(12,2) | YES | `NULL` |  |
| `vat_credit` | decimal(12,2) | YES | `NULL` |  |
| `deposit_credit` | decimal(12,2) | YES | `NULL` |  |
| `cardoo_debit` | decimal(12,2) | YES | `NULL` |  |
| `cardoo_credit` | decimal(12,2) | YES | `NULL` |  |
| `cardoo_deposit` | decimal(12,2) | YES | `NULL` |  |
| `contract_debit` | decimal(12,2) | YES | `NULL` |  |
| `contract_credit` | decimal(12,2) | YES | `NULL` |  |
| `contract_balance` | decimal(12,2) | YES | `NULL` |  |
| `contract_refunds` | decimal(12,2) | YES | `NULL` |  |
| `contract_discount` | decimal(12,2) | YES | `NULL` |  |
| `contract_bad_debts` | decimal(12,2) | YES | `NULL` |  |
| `contract_deposit` | decimal(12,2) | YES | `NULL` |  |
| `contract_commissions` | decimal(12,2) | YES | `NULL` |  |
| `contract_income` | decimal(12,2) | YES | `NULL` |  |
| `miles_allowed_pd` | decimal(12,2) | YES | `NULL` |  |
| `miles_allowed_pm` | decimal(12,2) | YES | `NULL` |  |
| `extra_mile_charge` | decimal(12,2) | YES | `NULL` |  |
| `cdw_rate` | decimal(12,2) | YES | `NULL` |  |
| `pai_rate` | decimal(12,2) | YES | `NULL` |  |
| `authorization_amount` | decimal(12,2) | YES | `NULL` |  |
| `insurance_type` | varchar(255) | YES | `NULL` |  |
| `trip_direction` | varchar(255) | YES | `NULL` |  |
| `under_claim` | tinyint(1) | YES | `NULL` |  |
| `guarantor_no` | varchar(255) | YES | `NULL` |  |
| `contract_status_no` | varchar(255) | YES | `NULL` |  |
| `reference` | varchar(255) | YES | `NULL` |  |
| `base_on` | varchar(255) | YES | `NULL` | indexed |
| `contract_serial` | varchar(255) | YES | `NULL` |  |
| `driver2` | varchar(255) | YES | `NULL` |  |
| `driver3` | varchar(255) | YES | `NULL` |  |
| `driver_out` | varchar(255) | YES | `NULL` |  |
| `driver_in` | varchar(255) | YES | `NULL` |  |
| `co_driver_cost` | decimal(12,2) | YES | `NULL` |  |
| `extra_driver_charge` | decimal(12,2) | YES | `NULL` |  |
| `gps_charge` | decimal(12,2) | YES | `NULL` |  |
| `fuel_charge` | decimal(12,2) | YES | `NULL` |  |
| `ra_vat_percentage` | decimal(8,4) | YES | `NULL` |  |
| `salesman_commission_no1` | varchar(255) | YES | `NULL` |  |
| `salesman_commission_value1` | decimal(12,2) | YES | `NULL` |  |
| `salesman_commission_no2` | varchar(255) | YES | `NULL` |  |
| `salesman_commission_value2` | decimal(12,2) | YES | `NULL` |  |
| `tax_inclusive` | tinyint(1) | YES | `NULL` |  |
| `cdw_on_contract` | tinyint(1) | YES | `NULL` |  |
| `credit_card_no` | varchar(255) | YES | `NULL` |  |
| `credit_card_expiry` | varchar(255) | YES | `NULL` |  |
| `authorization_date` | date | YES | `NULL` |  |
| `out_date_hijri` | varchar(255) | YES | `NULL` |  |
| `in_date_hijri` | varchar(255) | YES | `NULL` |  |
| `remarks` | text | YES | `NULL` |  |
| `sales_man1` | varchar(255) | YES | `NULL` |  |
| `sales_man2` | varchar(255) | YES | `NULL` |  |
| `source` | varchar(255) | YES | `NULL` |  |
| `external_id` | varchar(255) | YES | `NULL` | indexed |
| `synced_at` | timestamp | YES | `NULL` |  |
| `origin` | enum('web','sheet','api') | NO | `'web'` |  |
| `condition_ack_grade` | varchar(10) | YES | `NULL` |  |
| `condition_ack_note` | text | YES | `NULL` |  |
| `condition_ack_by` | varchar(255) | YES | `NULL` |  |
| `condition_ack_at` | timestamp | YES | `NULL` |  |
| `garage_vendor_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `garage_recorded_by` | bigint(20) unsigned | YES | `NULL` |  |
| `garage_recorded_at` | datetime | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |
| `deleted_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `parent_contract_id` → `contracts.id` · `garage_vendor_id` → `vendors.id` · `customer_id` → `customers.id` · `vehicle_id` → `vehicles.id`

**Indexes:** `base_on` · `car_serial` · `contract_no,contract_type` · `contract_type,out_date` · `customer_id` · `external_id` · `garage_vendor_id` · `parent_contract_id` · `state,in_date` · `vehicle_id`

### `contract_mileage_readings`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `contract_id` | bigint(20) unsigned | NO |  | indexed |
| `vehicle_id` | bigint(20) unsigned | NO |  | indexed |
| `odometer` | int(10) unsigned | NO |  |  |
| `reported_on` | date | NO |  |  |
| `recorded_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `reported_by` | varchar(255) | YES | `NULL` |  |
| `source` | varchar(32) | NO | `'customer_reported'` |  |
| `note` | text | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `vehicle_id` → `vehicles.id` · `recorded_by` → `users.id` · `contract_id` → `contracts.id`

**Indexes:** `contract_id,reported_on` · `recorded_by` · `vehicle_id,reported_on`

### `contract_oil_decisions`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `contract_id` | bigint(20) unsigned | NO |  | indexed |
| `vehicle_id` | bigint(20) unsigned | NO |  | indexed |
| `decision` | varchar(16) | NO |  |  |
| `anchor_reading_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `oil_limit` | int(10) unsigned | YES | `NULL` |  |
| `allowed_max` | int(10) unsigned | YES | `NULL` |  |
| `expected_return_odometer` | int(10) unsigned | YES | `NULL` |  |
| `remaining_days` | smallint(5) unsigned | YES | `NULL` |  |
| `decided_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `decided_by_name` | varchar(255) | YES | `NULL` |  |
| `note` | text | YES | `NULL` |  |
| `sales_confirmed_at` | datetime | YES | `NULL` |  |
| `sales_confirmed_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `sales_confirmed_by_name` | varchar(120) | YES | `NULL` |  |
| `sales_note` | varchar(1000) | YES | `NULL` |  |
| `collection_task_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `test_required` | tinyint(1) | YES | `NULL` |  |
| `service_location` | varchar(16) | YES | `NULL` |  |
| `request_adopted` | tinyint(1) | NO | `0` |  |
| `oil_changed_at` | datetime | YES | `NULL` | indexed |
| `oil_changed_odometer` | int(10) unsigned | YES | `NULL` |  |
| `oil_changed_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `oil_changed_by_name` | varchar(120) | YES | `NULL` |  |
| `oil_change_note` | varchar(1000) | YES | `NULL` |  |
| `returned_to_customer_at` | datetime | YES | `NULL` |  |
| `returned_to_customer_by_name` | varchar(120) | YES | `NULL` |  |
| `return_reminder_at` | datetime | YES | `NULL` |  |
| `is_auto` | tinyint(1) | NO | `0` |  |
| `settled_at` | timestamp | YES | `NULL` |  |
| `settled_ticket_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `inspection_ticket_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `inspection_ticket_id` → `maintenances.id` · `vehicle_id` → `vehicles.id` · `decided_by` → `users.id` · `settled_ticket_id` → `maintenances.id` · `contract_id` → `contracts.id` · `sales_confirmed_by` → `users.id` · `anchor_reading_id` → `contract_mileage_readings.id` · `oil_changed_by` → `users.id`

**Indexes:** `oil_changed_at,returned_to_customer_at` · `anchor_reading_id` · `collection_task_id` · `contract_id,id` · `decided_by` · `inspection_ticket_id` · `oil_changed_at` · `oil_changed_by` · `sales_confirmed_by` · `settled_ticket_id` · `vehicle_id,settled_at`

### `cost_adjustments`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `maintenance_id` | bigint(20) unsigned | NO |  | indexed |
| `maintenance_task_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `vehicle_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `applies_to` | varchar(10) | NO |  |  |
| `direction` | varchar(6) | NO |  |  |
| `amount` | decimal(12,2) | NO |  |  |
| `currency` | varchar(3) | NO | `'AED'` |  |
| `reason_code` | varchar(24) | NO |  | indexed |
| `reason_note` | text | NO |  |  |
| `reference` | varchar(120) | YES | `NULL` |  |
| `photo_disk` | varchar(20) | YES | `NULL` |  |
| `photo_key` | varchar(255) | YES | `NULL` |  |
| `vendor_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `line_item_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `approved_by` | bigint(20) unsigned | NO |  | indexed |
| `approved_by_name` | varchar(255) | NO |  |  |
| `approved_at` | timestamp | NO | `current_timestamp()` |  |
| `created_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |
| `status` | varchar(24) | NO | `'approved'` | indexed |

**Foreign keys:** `maintenance_id` → `maintenances.id` · `line_item_id` → `maintenance_line_items.id` · `vendor_id` → `vendors.id` · `created_by` → `users.id` · `vehicle_id` → `vehicles.id` · `approved_by` → `users.id` · `maintenance_task_id` → `maintenance_tasks.id`

**Indexes:** `approved_by` · `created_by` · `line_item_id` · `maintenance_id,applies_to` · `maintenance_task_id` · `reason_code` · `status` · `vehicle_id` · `vendor_id`

### `customers`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `customer_no` | varchar(255) | YES | `NULL` | unique |
| `name_en` | varchar(255) | YES | `NULL` |  |
| `name_ar` | varchar(255) | YES | `NULL` |  |
| `nationality` | varchar(255) | YES | `NULL` |  |
| `date_of_birth` | date | YES | `NULL` |  |
| `mobile1` | varchar(255) | YES | `NULL` |  |
| `mobile2` | varchar(255) | YES | `NULL` |  |
| `whatsapp` | varchar(255) | YES | `NULL` |  |
| `email` | varchar(255) | YES | `NULL` |  |
| `sex` | varchar(255) | YES | `NULL` |  |
| `city` | varchar(255) | YES | `NULL` |  |
| `address` | varchar(255) | YES | `NULL` |  |
| `po_box` | varchar(255) | YES | `NULL` |  |
| `passport_no` | varchar(255) | YES | `NULL` |  |
| `passport_expiry` | date | YES | `NULL` |  |
| `license_no` | varchar(255) | YES | `NULL` |  |
| `license_expiry` | date | YES | `NULL` |  |
| `id_no` | varchar(255) | YES | `NULL` |  |
| `id_expiry` | date | YES | `NULL` |  |
| `residency_no` | varchar(255) | YES | `NULL` |  |
| `residency_expiry` | date | YES | `NULL` |  |
| `traffic_file_no` | varchar(255) | YES | `NULL` |  |
| `vat_number` | varchar(255) | YES | `NULL` |  |
| `makani` | varchar(255) | YES | `NULL` |  |
| `lat` | decimal(10,7) | YES | `NULL` |  |
| `lon` | decimal(10,7) | YES | `NULL` |  |
| `debit` | decimal(12,2) | YES | `NULL` |  |
| `credit` | decimal(12,2) | YES | `NULL` |  |
| `balance` | decimal(12,2) | YES | `NULL` | indexed |
| `deposit` | decimal(12,2) | YES | `NULL` |  |
| `external_id` | varchar(255) | YES | `NULL` | indexed |
| `synced_at` | timestamp | YES | `NULL` |  |
| `origin` | enum('web','sheet','api') | NO | `'web'` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |
| `deleted_at` | timestamp | YES | `NULL` |  |

**Indexes:** `balance` · `customer_no` *(unique)* · `external_id`

### `damage_catalog`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `slug` | varchar(80) | NO |  | unique |
| `name` | varchar(120) | NO |  |  |
| `name_ar` | varchar(120) | YES | `NULL` |  |
| `category_key` | varchar(40) | NO |  | indexed |
| `location_mode` | varchar(12) | NO | `'optional'` |  |
| `area_key` | varchar(40) | YES | `NULL` | indexed |
| `damage_type` | varchar(20) | NO | `'unknown'` | indexed |
| `is_chargeable` | tinyint(1) | NO | `1` |  |
| `is_insurable` | tinyint(1) | NO | `0` |  |
| `affects_roadworthiness` | tinyint(1) | NO | `0` |  |
| `is_active` | tinyint(1) | NO | `1` |  |
| `sort_order` | smallint(5) unsigned | NO | `0` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `area_key` · `category_key` · `damage_type` · `slug` *(unique)*

### `domain_events`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `event_type` | varchar(64) | NO |  | indexed |
| `event_version` | smallint(5) unsigned | NO | `1` |  |
| `layer` | varchar(16) | NO |  |  |
| `vehicle_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `maintenance_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `maintenance_task_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `subject_type` | varchar(64) | YES | `NULL` | indexed |
| `subject_id` | bigint(20) unsigned | YES | `NULL` |  |
| `payload` | longtext | NO |  |  |
| `observed_by` | bigint(20) unsigned | YES | `NULL` |  |
| `observed_by_name` | varchar(255) | YES | `NULL` |  |
| `actor_type` | varchar(24) | NO |  |  |
| `organization_id` | bigint(20) unsigned | YES | `NULL` |  |
| `capture_method` | varchar(24) | NO |  |  |
| `trust_level` | tinyint(3) unsigned | NO |  |  |
| `source_system` | varchar(32) | NO | `'fleet'` |  |
| `is_self_reported` | tinyint(1) | NO | `0` |  |
| `supersedes_event_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `supersede_reason` | varchar(255) | YES | `NULL` |  |
| `correlation_id` | char(36) | YES | `NULL` | indexed |
| `occurred_at` | timestamp | NO | `current_timestamp()` |  |
| `recorded_at` | timestamp | NO | `current_timestamp()` |  |

**Indexes:** `correlation_id` · `event_type,occurred_at` · `maintenance_id,event_type` · `maintenance_task_id,event_type` · `subject_type,subject_id` · `supersedes_event_id` · `vehicle_id,occurred_at`

### `drivers`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `user_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `name` | varchar(255) | NO |  |  |
| `license_no` | varchar(255) | YES | `NULL` |  |
| `license_expiry` | date | YES | `NULL` |  |
| `phone` | varchar(255) | YES | `NULL` |  |
| `status` | enum('active','suspended') | NO | `'active'` |  |
| `external_id` | varchar(255) | YES | `NULL` | indexed |
| `synced_at` | timestamp | YES | `NULL` |  |
| `origin` | enum('web','sheet') | NO | `'web'` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |
| `deleted_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `user_id` → `users.id`

**Indexes:** `external_id` · `user_id`

### `driver_observations`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `vehicle_id` | bigint(20) unsigned | NO |  | indexed |
| `driver_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `contract_id` | bigint(20) unsigned | YES | `NULL` |  |
| `note` | text | NO |  |  |
| `photo` | varchar(255) | YES | `NULL` |  |
| `status` | varchar(20) | NO | `'open'` | indexed |
| `inspection_request_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `created_at` | timestamp | YES | `NULL` | indexed |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `created_at` · `driver_id` · `inspection_request_id` · `status` · `vehicle_id`

### `evidence_links`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `evidenceable_type` | varchar(255) | NO |  | indexed |
| `evidenceable_id` | bigint(20) unsigned | NO |  |  |
| `knowledge_source_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `knowledge_document_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `knowledge_chunk_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `retrieval_method` | varchar(20) | NO | `'model_prior'` | indexed |
| `document_title` | varchar(300) | YES | `NULL` |  |
| `section` | varchar(300) | YES | `NULL` |  |
| `url` | varchar(1000) | YES | `NULL` |  |
| `snippet` | text | YES | `NULL` |  |
| `confidence` | tinyint(3) unsigned | NO | `50` |  |
| `retrieved_at` | timestamp | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `knowledge_chunk_id` → `knowledge_chunks.id` · `knowledge_source_id` → `knowledge_sources.id` · `knowledge_document_id` → `knowledge_documents.id`

**Indexes:** `evidenceable_type,evidenceable_id` · `knowledge_chunk_id` · `knowledge_document_id` · `knowledge_source_id` · `retrieval_method` · `retrieval_method,confidence`

### `failed_jobs`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `uuid` | varchar(255) | NO |  | unique |
| `connection` | text | NO |  |  |
| `queue` | text | NO |  |  |
| `payload` | longtext | NO |  |  |
| `exception` | longtext | NO |  |  |
| `failed_at` | timestamp | NO | `current_timestamp()` |  |

**Indexes:** `uuid` *(unique)*

### `fault_catalog`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `slug` | varchar(80) | NO |  | unique |
| `name` | varchar(120) | NO |  |  |
| `name_ar` | varchar(120) | YES | `NULL` |  |
| `category_key` | varchar(40) | NO |  | indexed |
| `location_mode` | varchar(12) | NO | `'optional'` |  |
| `default_severity` | varchar(10) | YES | `NULL` |  |
| `on_site` | tinyint(1) | NO | `0` |  |
| `is_active` | tinyint(1) | NO | `1` |  |
| `sort_order` | smallint(5) unsigned | NO | `0` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `category_key` · `slug` *(unique)*

### `fault_causes`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `symptom_key` | varchar(191) | NO |  | indexed |
| `symptom_label` | varchar(191) | NO |  |  |
| `category_key` | varchar(60) | YES | `NULL` |  |
| `fault_catalog_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `root_cause` | varchar(191) | NO |  |  |
| `description` | varchar(500) | YES | `NULL` |  |
| `status` | varchar(20) | NO | `'approved'` | indexed |
| `source` | varchar(20) | NO | `'seed'` |  |
| `usage_count` | int(10) unsigned | NO | `0` |  |
| `odoo_ref` | varchar(120) | YES | `NULL` | indexed |
| `submitted_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `reviewed_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `reviewed_at` | timestamp | YES | `NULL` |  |
| `review_note` | varchar(500) | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `fault_catalog_id` → `fault_catalog.id` · `submitted_by` → `users.id` · `reviewed_by` → `users.id`

**Indexes:** `fault_catalog_id` · `odoo_ref` · `reviewed_by` · `status` · `submitted_by` · `symptom_key,root_cause` *(unique)* · `symptom_key`

### `fault_concept_actions`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `finding_keyword_id` | bigint(20) unsigned | NO |  | indexed |
| `action_catalog_id` | bigint(20) unsigned | NO |  | indexed |
| `relevance` | varchar(16) | NO | `'typical'` |  |
| `sort_order` | int(10) unsigned | NO | `0` |  |
| `source` | varchar(16) | NO | `'seed'` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `action_catalog_id` · `finding_keyword_id,action_catalog_id` *(unique)* · `finding_keyword_id,relevance`

### `fault_recurrence_pairs`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `vehicle_id` | bigint(20) unsigned | NO |  | indexed |
| `signature` | varchar(24) | NO |  | indexed |
| `kind` | varchar(12) | NO | `'fault'` | indexed |
| `occurred_at` | date | NO |  |  |
| `first_maintenance_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `first_vendor_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `next_occurred_at` | date | YES | `NULL` |  |
| `next_maintenance_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `next_vendor_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `days_to_return` | smallint(5) unsigned | YES | `NULL` | indexed |
| `days_observed` | smallint(5) unsigned | YES | `NULL` | indexed |
| `returned_30` | tinyint(1) | NO | `0` |  |
| `returned_60` | tinyint(1) | NO | `0` |  |
| `returned_90` | tinyint(1) | NO | `0` |  |
| `same_vendor` | tinyint(1) | YES | `NULL` |  |
| `label_source` | varchar(12) | NO | `'derived'` |  |
| `source_row_count` | smallint(5) unsigned | NO | `1` |  |
| `multi_vendor_day` | tinyint(1) | NO | `0` |  |
| `chain_position` | smallint(5) unsigned | NO | `1` |  |
| `chain_length` | smallint(5) unsigned | NO | `1` |  |
| `built_at` | timestamp | YES | `NULL` |  |

**Indexes:** `days_observed` · `days_to_return` · `first_vendor_id,signature` · `signature,occurred_at` · `vehicle_id,signature,occurred_at` *(unique)* · `first_maintenance_id` · `kind,days_observed` · `next_maintenance_id` · `next_vendor_id`

### `finding_keywords`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `category_key` | varchar(60) | NO |  | indexed |
| `category_label` | varchar(80) | NO |  |  |
| `category_label_ar` | varchar(80) | YES | `NULL` |  |
| `keyword` | varchar(191) | NO |  |  |
| `keyword_ar` | varchar(191) | YES | `NULL` |  |
| `risk` | varchar(20) | NO | `'moderate'` | indexed |
| `description` | varchar(500) | YES | `NULL` |  |
| `is_active` | tinyint(1) | NO | `1` | indexed |
| `sort_order` | int(10) unsigned | NO | `0` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `category_key,keyword` *(unique)* · `category_key` · `is_active` · `risk`

### `garage_invoice_submissions`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `maintenance_id` | bigint(20) unsigned | NO |  | indexed |
| `vendor_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `token` | varchar(64) | NO |  | unique |
| `expires_at` | timestamp | YES | `NULL` |  |
| `status` | varchar(20) | NO | `'pending'` | indexed |
| `line_items` | longtext | YES | `NULL` |  |
| `parts_total` | decimal(12,2) | YES | `NULL` |  |
| `labor_total` | decimal(12,2) | YES | `NULL` |  |
| `itemized_total` | decimal(12,2) | YES | `NULL` |  |
| `receipt_total` | decimal(12,2) | YES | `NULL` |  |
| `variance` | decimal(12,2) | YES | `NULL` |  |
| `variance_explanation` | text | YES | `NULL` |  |
| `garage_note` | text | YES | `NULL` |  |
| `receipt_photo_disk` | varchar(20) | YES | `NULL` |  |
| `receipt_photo_key` | varchar(255) | YES | `NULL` |  |
| `created_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `submitted_at` | timestamp | YES | `NULL` |  |
| `submitted_ip` | varchar(45) | YES | `NULL` |  |
| `reviewed_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `reviewed_at` | timestamp | YES | `NULL` |  |
| `review_note` | text | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `vendor_id` → `vendors.id` · `reviewed_by` → `users.id` · `maintenance_id` → `maintenances.id` · `created_by` → `users.id`

**Indexes:** `created_by` · `maintenance_id,status` · `maintenance_id,vendor_id,status` · `reviewed_by` · `status` · `token` *(unique)* · `vendor_id`

### `garage_recommendation_decisions`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `maintenance_id` | bigint(20) unsigned | NO |  | indexed |
| `vehicle_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `recommended_vendor_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `chosen_vendor_id` | bigint(20) unsigned | NO |  | indexed |
| `accepted` | tinyint(1) | NO | `0` |  |
| `followed` | tinyint(1) | YES | `NULL` | indexed |
| `override_reason` | varchar(40) | YES | `NULL` | indexed |
| `override_note` | text | YES | `NULL` |  |
| `rank` | smallint(5) unsigned | YES | `NULL` |  |
| `chosen_rank` | smallint(5) unsigned | YES | `NULL` |  |
| `score` | decimal(8,4) | YES | `NULL` |  |
| `match_score` | tinyint(3) unsigned | YES | `NULL` |  |
| `chosen_match_score` | tinyint(3) unsigned | YES | `NULL` |  |
| `score_gap` | smallint(6) | YES | `NULL` |  |
| `chosen_advantages` | longtext | YES | `NULL` |  |
| `confidence` | varchar(12) | YES | `NULL` |  |
| `reasons` | longtext | YES | `NULL` |  |
| `breakdown` | longtext | YES | `NULL` |  |
| `strategy` | longtext | YES | `NULL` |  |
| `expected_outcomes` | longtext | YES | `NULL` |  |
| `fault_criticality` | longtext | YES | `NULL` |  |
| `actual_outcomes` | longtext | YES | `NULL` |  |
| `forecast_accuracy` | longtext | YES | `NULL` |  |
| `scored_at` | timestamp | YES | `NULL` | indexed |
| `criteria` | longtext | YES | `NULL` |  |
| `actor_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `engine_version` | varchar(40) | YES | `NULL` | indexed |
| `policy_version` | varchar(40) | YES | `NULL` |  |
| `config_fingerprint` | varchar(16) | YES | `NULL` |  |
| `data_snapshot` | date | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `maintenance_id` → `maintenances.id` · `chosen_vendor_id` → `vendors.id` · `actor_id` → `users.id` · `vehicle_id` → `vehicles.id` · `recommended_vendor_id` → `vendors.id`

**Indexes:** `actor_id` · `chosen_vendor_id` · `engine_version` · `maintenance_id,created_at` · `recommended_vendor_id` · `scored_at` · `vehicle_id,created_at` · `followed,created_at` · `override_reason`

### `garage_routing_rules`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `vendor_id` | bigint(20) unsigned | NO |  | indexed |
| `dimension` | varchar(30) | NO |  | indexed |
| `match_key` | varchar(60) | NO |  | indexed |
| `weight` | int(11) | NO | `10` |  |
| `is_specialist` | tinyint(1) | NO | `0` |  |
| `active` | tinyint(1) | NO | `1` | indexed |
| `note` | varchar(500) | YES | `NULL` |  |
| `created_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `updated_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `vendor_id` → `vendors.id` · `updated_by` → `users.id` · `created_by` → `users.id`

**Indexes:** `active` · `created_by` · `dimension` · `match_key` · `vendor_id,dimension,match_key` *(unique)* · `updated_by`

### `inspection_records`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `contract_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `vehicle_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `inspection_session_id` | char(36) | YES | `NULL` | indexed |
| `inspector_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `inspector_name` | varchar(255) | YES | `NULL` |  |
| `phase` | varchar(20) | NO | `'pre'` |  |
| `body_part` | varchar(40) | NO |  |  |
| `checkpoint_type` | varchar(20) | YES | `NULL` | indexed |
| `s3_disk` | varchar(30) | NO | `'s3'` |  |
| `s3_key` | varchar(255) | YES | `NULL` |  |
| `mime_type` | varchar(60) | YES | `NULL` |  |
| `file_size` | bigint(20) unsigned | YES | `NULL` |  |
| `width` | int(10) unsigned | YES | `NULL` |  |
| `height` | int(10) unsigned | YES | `NULL` |  |
| `damage_flagged` | tinyint(1) | NO | `0` | indexed |
| `damage_type` | varchar(20) | YES | `NULL` |  |
| `severity` | varchar(10) | YES | `NULL` |  |
| `note` | text | YES | `NULL` |  |
| `reviewed_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `reviewed_at` | timestamp | YES | `NULL` |  |
| `review_outcome` | varchar(20) | YES | `NULL` |  |
| `captured_at` | timestamp | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `vehicle_id` → `vehicles.id` · `reviewed_by` → `users.id` · `inspector_id` → `users.id` · `contract_id` → `contracts.id`

**Indexes:** `checkpoint_type` · `contract_id,phase,body_part` · `damage_flagged` · `inspection_session_id` · `inspector_id` · `reviewed_by` · `vehicle_id`

### `inspection_schedules`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `vehicle_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `name` | varchar(255) | NO |  |  |
| `description` | text | YES | `NULL` |  |
| `pillar` | varchar(30) | YES | `NULL` |  |
| `interval_type` | varchar(10) | NO | `'time'` |  |
| `interval_days` | int(10) unsigned | YES | `NULL` |  |
| `interval_km` | int(10) unsigned | YES | `NULL` |  |
| `last_inspected_at` | timestamp | YES | `NULL` |  |
| `last_inspected_odometer` | int(10) unsigned | YES | `NULL` |  |
| `next_due_at` | timestamp | YES | `NULL` | indexed |
| `next_due_odometer` | int(10) unsigned | YES | `NULL` |  |
| `assigned_to` | bigint(20) unsigned | YES | `NULL` | indexed |
| `active` | tinyint(1) | NO | `1` | indexed |
| `notes` | text | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `vehicle_id` → `vehicles.id` · `assigned_to` → `users.id`

**Indexes:** `active` · `assigned_to` · `next_due_at` · `vehicle_id`

### `inspection_types`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `slug` | varchar(80) | NO |  | unique |
| `name` | varchar(120) | NO |  |  |
| `name_ar` | varchar(120) | YES | `NULL` |  |
| `checklist_key` | varchar(60) | YES | `NULL` |  |
| `expects_measurements` | tinyint(1) | NO | `0` |  |
| `may_spawn_fault` | tinyint(1) | NO | `1` |  |
| `is_active` | tinyint(1) | NO | `1` |  |
| `sort_order` | smallint(5) unsigned | NO | `0` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `slug` *(unique)*

### `inspector_pad_flags`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `vehicle_id` | bigint(20) unsigned | NO |  | indexed |
| `keyword` | varchar(255) | YES | `NULL` |  |
| `observation` | text | YES | `NULL` |  |
| `severity` | varchar(20) | YES | `NULL` |  |
| `status` | varchar(20) | NO | `'pending'` |  |
| `created_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `consumed_by_maintenance_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `consumed_at` | timestamp | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `vehicle_id` → `vehicles.id` · `created_by` → `users.id` · `consumed_by_maintenance_id` → `maintenances.id`

**Indexes:** `consumed_by_maintenance_id` · `created_by` · `vehicle_id,status`

### `intelligence_rebuild_runs`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `command` | varchar(60) | NO |  | indexed |
| `target_table` | varchar(60) | NO |  | indexed |
| `status` | varchar(16) | NO |  | indexed |
| `started_at` | timestamp | NO | `current_timestamp()` |  |
| `finished_at` | timestamp | YES | `NULL` |  |
| `duration_ms` | int(10) unsigned | YES | `NULL` |  |
| `rows_read` | int(10) unsigned | YES | `NULL` |  |
| `rows_written` | int(10) unsigned | YES | `NULL` |  |
| `corpus_max_date` | date | YES | `NULL` |  |
| `metric_version` | varchar(20) | YES | `NULL` |  |
| `failure_reason` | text | YES | `NULL` |  |
| `stats` | longtext | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `command,started_at` · `status` · `target_table,status`

### `invoices`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `invoice_no` | bigint(20) unsigned | YES | `NULL` | unique |
| `invoice_ref` | varchar(255) | YES | `NULL` | unique |
| `invoice_date` | date | YES | `NULL` |  |
| `customer_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `contract_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `vehicle_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `vendor_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `contract_serial` | bigint(20) unsigned | YES | `NULL` | indexed |
| `car_no` | varchar(255) | YES | `NULL` |  |
| `contract_out_date` | date | YES | `NULL` |  |
| `contract_in_date` | date | YES | `NULL` |  |
| `rent_days` | int(11) | YES | `NULL` |  |
| `net_rate` | decimal(14,2) | YES | `NULL` |  |
| `car_serial` | bigint(20) unsigned | YES | `NULL` | indexed |
| `total_value` | decimal(14,2) | YES | `NULL` |  |
| `vat_value` | decimal(14,2) | YES | `NULL` |  |
| `total_after_vat` | decimal(14,2) | YES | `NULL` |  |
| `status_no` | smallint(5) unsigned | YES | `NULL` |  |
| `balance_value` | decimal(14,2) | YES | `NULL` |  |
| `paid_amount` | decimal(14,2) | YES | `NULL` |  |
| `payment_status` | varchar(12) | YES | `NULL` | indexed |
| `discount` | decimal(12,2) | YES | `NULL` |  |
| `total_after_discount` | decimal(12,2) | YES | `NULL` |  |
| `period_from` | date | YES | `NULL` |  |
| `period_to` | date | YES | `NULL` |  |
| `synced_at` | timestamp | YES | `NULL` |  |
| `origin` | varchar(255) | NO | `'api'` |  |
| `notes` | text | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `contract_id` → `contracts.id` · `customer_id` → `customers.id`

**Indexes:** `car_serial` · `contract_id` · `contract_serial` · `customer_id` · `invoice_no` *(unique)* · `invoice_ref` *(unique)* · `payment_status` · `vehicle_id` · `vendor_id`

### `invoice_items`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `invoice_id` | bigint(20) unsigned | NO |  | indexed |
| `description` | varchar(255) | NO |  |  |
| `category_key` | varchar(40) | YES | `NULL` | indexed |
| `sequence` | smallint(5) unsigned | NO | `0` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `invoice_id` → `invoices.id`

**Indexes:** `category_key` · `invoice_id`

### `jobs`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `queue` | varchar(255) | NO |  | indexed |
| `payload` | longtext | NO |  |  |
| `attempts` | tinyint(3) unsigned | NO |  |  |
| `reserved_at` | int(10) unsigned | YES | `NULL` |  |
| `available_at` | int(10) unsigned | NO |  |  |
| `created_at` | int(10) unsigned | NO |  |  |

**Indexes:** `queue`

### `job_batches`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar(255) | NO |  | PK |
| `name` | varchar(255) | NO |  |  |
| `total_jobs` | int(11) | NO |  |  |
| `pending_jobs` | int(11) | NO |  |  |
| `failed_jobs` | int(11) | NO |  |  |
| `failed_job_ids` | longtext | NO |  |  |
| `options` | mediumtext | YES | `NULL` |  |
| `cancelled_at` | int(11) | YES | `NULL` |  |
| `created_at` | int(11) | NO |  |  |
| `finished_at` | int(11) | YES | `NULL` |  |

### `keyword_enrichment_runs`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `finding_keyword_id` | bigint(20) unsigned | NO |  | indexed |
| `status` | varchar(12) | NO | `'pending'` | indexed |
| `model` | varchar(60) | YES | `NULL` |  |
| `research_provider` | varchar(40) | YES | `NULL` |  |
| `research_model` | varchar(80) | YES | `NULL` |  |
| `extraction_provider` | varchar(40) | YES | `NULL` |  |
| `extraction_model` | varchar(80) | YES | `NULL` |  |
| `embedding_model` | varchar(80) | YES | `NULL` |  |
| `prompt_version` | varchar(20) | YES | `NULL` | indexed |
| `ontology_version` | varchar(20) | YES | `NULL` |  |
| `retrieval_version` | varchar(20) | YES | `NULL` |  |
| `source_snapshot` | longtext | YES | `NULL` |  |
| `retrieval_summary` | longtext | YES | `NULL` |  |
| `confidence_breakdown` | longtext | YES | `NULL` |  |
| `scope_key` | varchar(120) | NO | `'*'` |  |
| `terms_added` | smallint(5) unsigned | NO | `0` |  |
| `terms_updated` | smallint(5) unsigned | NO | `0` |  |
| `profile_written` | tinyint(1) | NO | `0` |  |
| `input_tokens` | int(10) unsigned | NO | `0` |  |
| `output_tokens` | int(10) unsigned | NO | `0` |  |
| `duration_ms` | int(10) unsigned | NO | `0` |  |
| `error` | varchar(500) | YES | `NULL` |  |
| `triggered_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `triggered_by` → `users.id` · `finding_keyword_id` → `finding_keywords.id`

**Indexes:** `prompt_version,ontology_version` · `status` · `triggered_by` · `finding_keyword_id,status,created_at`

### `keyword_profiles`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `finding_keyword_id` | bigint(20) unsigned | NO |  | indexed |
| `vehicle_system` | varchar(80) | YES | `NULL` | indexed |
| `subsystem` | varchar(80) | YES | `NULL` |  |
| `repair_discipline` | varchar(40) | YES | `NULL` | indexed |
| `complexity` | varchar(20) | YES | `NULL` |  |
| `labor_hours_min` | decimal(5,2) | YES | `NULL` |  |
| `labor_hours_max` | decimal(5,2) | YES | `NULL` |  |
| `fleet_labor_hours_avg` | decimal(5,2) | YES | `NULL` |  |
| `fleet_case_count` | int(10) unsigned | NO | `0` |  |
| `required_tools` | longtext | YES | `NULL` |  |
| `required_skills` | longtext | YES | `NULL` |  |
| `inspection_order` | longtext | YES | `NULL` |  |
| `cost_min` | decimal(10,2) | YES | `NULL` |  |
| `cost_max` | decimal(10,2) | YES | `NULL` |  |
| `cost_currency` | varchar(3) | NO | `'AED'` |  |
| `severity_estimate` | varchar(20) | YES | `NULL` | indexed |
| `summary_en` | text | YES | `NULL` |  |
| `summary_ar` | text | YES | `NULL` |  |
| `symptoms` | longtext | YES | `NULL` |  |
| `components` | longtext | YES | `NULL` |  |
| `likely_causes` | longtext | YES | `NULL` |  |
| `repair_actions` | longtext | YES | `NULL` |  |
| `related_faults` | longtext | YES | `NULL` |  |
| `evidence_sources` | longtext | YES | `NULL` |  |
| `confidence` | tinyint(3) unsigned | NO | `0` |  |
| `grounding_score` | tinyint(3) unsigned | NO | `0` |  |
| `make` | varchar(60) | YES | `NULL` |  |
| `model_name` | varchar(60) | YES | `NULL` |  |
| `generation` | varchar(60) | YES | `NULL` |  |
| `scope_key` | varchar(120) | NO | `'*'` |  |
| `model` | varchar(60) | YES | `NULL` |  |
| `enriched_at` | timestamp | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `finding_keyword_id` → `finding_keywords.id`

**Indexes:** `finding_keyword_id,scope_key` *(unique)* · `repair_discipline` · `severity_estimate` · `vehicle_system`

### `keyword_terms`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `finding_keyword_id` | bigint(20) unsigned | NO |  | indexed |
| `term` | varchar(191) | NO |  |  |
| `normalized` | varchar(191) | NO |  | indexed |
| `lang` | varchar(5) | NO | `'en'` | indexed |
| `kind` | varchar(24) | NO | `'synonym'` | indexed |
| `confidence` | tinyint(3) unsigned | NO | `80` |  |
| `source` | varchar(12) | NO | `'ai'` | indexed |
| `source_quality` | varchar(20) | YES | `NULL` |  |
| `workshop_frequency` | varchar(20) | YES | `NULL` |  |
| `search_rank` | tinyint(3) unsigned | NO | `50` | indexed |
| `is_active` | tinyint(1) | NO | `1` | indexed |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `finding_keyword_id` → `finding_keywords.id`

**Indexes:** `finding_keyword_id,normalized` *(unique)* · `is_active` · `kind` · `lang` · `normalized` · `search_rank` · `source`

### `knowledge_chunks`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `knowledge_document_id` | bigint(20) unsigned | NO |  | indexed |
| `ordinal` | int(10) unsigned | NO | `0` |  |
| `section` | varchar(300) | YES | `NULL` |  |
| `heading` | varchar(300) | YES | `NULL` |  |
| `text` | text | NO |  |  |
| `token_count` | int(10) unsigned | NO | `0` |  |
| `normalized` | mediumtext | YES | `NULL` |  |
| `embedding` | longtext | YES | `NULL` |  |
| `embedding_model` | varchar(60) | YES | `NULL` | indexed |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `knowledge_document_id` → `knowledge_documents.id`

**Indexes:** `embedding_model` · `knowledge_document_id,ordinal`

### `knowledge_documents`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `knowledge_source_id` | bigint(20) unsigned | NO |  | indexed |
| `title` | varchar(300) | NO |  |  |
| `doc_type` | varchar(30) | NO | `'repair_guide'` | indexed |
| `url` | varchar(1000) | YES | `NULL` |  |
| `publisher` | varchar(160) | YES | `NULL` |  |
| `published_at` | date | YES | `NULL` |  |
| `language` | varchar(5) | NO | `'en'` |  |
| `make` | varchar(60) | YES | `NULL` | indexed |
| `model` | varchar(60) | YES | `NULL` |  |
| `year_from` | smallint(5) unsigned | YES | `NULL` |  |
| `year_to` | smallint(5) unsigned | YES | `NULL` |  |
| `engine` | varchar(60) | YES | `NULL` |  |
| `platform` | varchar(60) | YES | `NULL` |  |
| `scope_key` | varchar(120) | NO | `'*'` | indexed |
| `checksum` | varchar(64) | YES | `NULL` | indexed |
| `chunk_count` | int(10) unsigned | NO | `0` |  |
| `ingested_at` | timestamp | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `knowledge_source_id` → `knowledge_sources.id`

**Indexes:** `checksum` · `doc_type` · `knowledge_source_id` · `make` · `scope_key`

### `knowledge_sources`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `key` | varchar(60) | NO |  | unique |
| `name` | varchar(160) | NO |  |  |
| `publisher` | varchar(160) | YES | `NULL` |  |
| `tier` | varchar(24) | NO | `'tier3_reference'` | indexed |
| `trust_weight` | tinyint(3) unsigned | NO | `60` |  |
| `access` | varchar(20) | NO | `'public_web'` | indexed |
| `domains` | longtext | YES | `NULL` |  |
| `base_url` | varchar(500) | YES | `NULL` |  |
| `notes` | varchar(500) | YES | `NULL` |  |
| `is_active` | tinyint(1) | NO | `1` | indexed |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `access` · `is_active` · `key` *(unique)* · `tier`

### `kpi_snapshots`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `label` | varchar(255) | NO |  |  |
| `metric_version` | varchar(20) | YES | `NULL` |  |
| `reason` | text | YES | `NULL` |  |
| `supersedes_snapshot_id` | bigint(20) unsigned | YES | `NULL` |  |
| `commit_ref` | varchar(60) | YES | `NULL` |  |
| `metrics` | longtext | NO |  |  |
| `source_state` | longtext | YES | `NULL` |  |
| `captured_at` | timestamp | NO | `current_timestamp()` | indexed |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `captured_at`

### `logistics_tasks`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `vehicle_id` | bigint(20) unsigned | NO |  | indexed |
| `vehicle_plate` | varchar(255) | YES | `NULL` |  |
| `vehicle_label` | varchar(255) | YES | `NULL` |  |
| `maintenance_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `purpose` | varchar(32) | YES | `NULL` | indexed |
| `destination` | varchar(255) | NO |  |  |
| `round_trip` | tinyint(1) | NO | `0` |  |
| `assigned_to_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `assigned_to_name` | varchar(255) | YES | `NULL` |  |
| `assigned_by_id` | bigint(20) unsigned | YES | `NULL` |  |
| `assigned_by_name` | varchar(255) | YES | `NULL` |  |
| `status` | varchar(255) | NO | `'in_transit'` |  |
| `status_changed_at` | timestamp | YES | `NULL` |  |
| `notes` | text | YES | `NULL` |  |
| `last_status` | varchar(255) | YES | `NULL` |  |
| `last_status_at` | timestamp | YES | `NULL` |  |
| `last_status_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `last_pinged_at` | timestamp | YES | `NULL` |  |
| `dispatched_at` | timestamp | YES | `NULL` |  |
| `claimed_at` | timestamp | YES | `NULL` |  |
| `completed_at` | timestamp | YES | `NULL` |  |
| `returned_at` | timestamp | YES | `NULL` |  |
| `returned_lat` | decimal(10,7) | YES | `NULL` |  |
| `returned_lng` | decimal(10,7) | YES | `NULL` |  |
| `returned_accuracy` | decimal(8,2) | YES | `NULL` |  |
| `completed_by_name` | varchar(255) | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `last_status_by` → `users.id`

**Indexes:** `assigned_to_id` · `assigned_to_id,status` · `last_status_by` · `maintenance_id` · `purpose` · `vehicle_id` · `vehicle_id,status`

### `logistics_task_events`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `logistics_task_id` | bigint(20) unsigned | NO |  | indexed |
| `vehicle_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `event` | varchar(30) | NO |  |  |
| `from_status` | varchar(30) | YES | `NULL` |  |
| `to_status` | varchar(30) | YES | `NULL` |  |
| `actor_id` | bigint(20) unsigned | YES | `NULL` |  |
| `actor_name` | varchar(255) | YES | `NULL` |  |
| `lat` | decimal(10,7) | YES | `NULL` |  |
| `lng` | decimal(10,7) | YES | `NULL` |  |
| `accuracy` | decimal(8,2) | YES | `NULL` |  |
| `note` | varchar(255) | YES | `NULL` |  |
| `occurred_at` | timestamp | NO | `current_timestamp()` | indexed |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `logistics_task_id` · `occurred_at` · `vehicle_id`

### `maintenances`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `contract_id` | bigint(20) unsigned | YES | `NULL` | unique |
| `linked_contract_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `vehicle_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `origin` | varchar(255) | NO | `'contract'` | indexed |
| `row_hash` | varchar(255) | YES | `NULL` | unique |
| `car_label` | varchar(255) | YES | `NULL` |  |
| `plate` | varchar(255) | YES | `NULL` |  |
| `event_status` | varchar(255) | YES | `NULL` |  |
| `workflow_status` | varchar(30) | YES | `NULL` | indexed |
| `repair_location` | varchar(255) | YES | `NULL` | indexed |
| `deferrable_for_rental` | tinyint(1) | NO | `0` |  |
| `last_state_change_at` | timestamp | YES | `NULL` |  |
| `paused_from_status` | varchar(40) | YES | `NULL` |  |
| `paused_at` | timestamp | YES | `NULL` |  |
| `paused_by` | bigint(20) unsigned | YES | `NULL` |  |
| `paused_reason` | text | YES | `NULL` |  |
| `vehicle_returned_at` | timestamp | YES | `NULL` |  |
| `vehicle_returned_by` | bigint(20) unsigned | YES | `NULL` |  |
| `active_incident_id` | bigint(20) unsigned | YES | `NULL` |  |
| `last_pause_handover_id` | bigint(20) unsigned | YES | `NULL` |  |
| `last_resume_handover_id` | bigint(20) unsigned | YES | `NULL` |  |
| `active_temporary_release_id` | bigint(20) unsigned | YES | `NULL` |  |
| `trigger_reason` | varchar(30) | YES | `NULL` |  |
| `request_origin` | varchar(30) | YES | `NULL` | indexed |
| `test_kind` | varchar(255) | YES | `NULL` | indexed |
| `customer_complaint` | text | YES | `NULL` |  |
| `request_detail_mode` | varchar(12) | YES | `NULL` |  |
| `reported_faults` | longtext | YES | `NULL` |  |
| `request_reason_code` | varchar(40) | YES | `NULL` |  |
| `requested_services` | longtext | YES | `NULL` |  |
| `suggested_findings` | longtext | YES | `NULL` |  |
| `trigger_detail` | longtext | YES | `NULL` |  |
| `test_drive_report` | longtext | YES | `NULL` |  |
| `findings` | longtext | YES | `NULL` |  |
| `test_odometer` | int(10) unsigned | YES | `NULL` |  |
| `report_odometer` | int(10) unsigned | YES | `NULL` |  |
| `intake_odometer` | int(10) unsigned | YES | `NULL` |  |
| `dispatch_odometer` | int(10) unsigned | YES | `NULL` |  |
| `receive_odometer` | int(10) unsigned | YES | `NULL` |  |
| `return_odometer` | int(10) unsigned | YES | `NULL` |  |
| `reinspect_odometer` | int(10) unsigned | YES | `NULL` |  |
| `park_odometer` | int(10) unsigned | YES | `NULL` |  |
| `odometer_flags` | longtext | YES | `NULL` |  |
| `garage_feedback` | text | YES | `NULL` |  |
| `inspected_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `inspected_at` | timestamp | YES | `NULL` |  |
| `test_started_at` | timestamp | YES | `NULL` |  |
| `dispatched_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `assigned_driver_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `recovery_unit_name` | varchar(255) | YES | `NULL` |  |
| `recovery_unit_phone` | varchar(255) | YES | `NULL` |  |
| `dispatched_at` | timestamp | YES | `NULL` |  |
| `repair_started_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `repair_started_at` | timestamp | YES | `NULL` |  |
| `ready_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `ready_at` | timestamp | YES | `NULL` |  |
| `picked_up_from_garage_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `picked_up_from_garage_at` | timestamp | YES | `NULL` |  |
| `park_arrived_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `park_arrived_at` | timestamp | YES | `NULL` |  |
| `returned_at` | timestamp | YES | `NULL` |  |
| `wf_closed_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `wf_closed_at` | timestamp | YES | `NULL` |  |
| `requested_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `requested_at` | timestamp | YES | `NULL` |  |
| `reviewed_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `reviewed_at` | timestamp | YES | `NULL` |  |
| `review_notes` | text | YES | `NULL` |  |
| `review_rejection_reason` | text | YES | `NULL` |  |
| `review_rejection_code` | varchar(40) | YES | `NULL` | indexed |
| `review_auto_context` | longtext | YES | `NULL` |  |
| `review_sent_at` | timestamp | YES | `NULL` |  |
| `follow_ups` | longtext | YES | `NULL` |  |
| `out_date` | date | YES | `NULL` |  |
| `follow_date` | date | YES | `NULL` |  |
| `actual_in_date` | date | YES | `NULL` |  |
| `base_on` | varchar(255) | YES | `NULL` |  |
| `driver` | varchar(255) | YES | `NULL` |  |
| `liable_party` | varchar(255) | YES | `NULL` |  |
| `charge_to` | varchar(255) | YES | `NULL` |  |
| `garage` | varchar(255) | YES | `NULL` |  |
| `maintenance_type` | varchar(255) | YES | `NULL` |  |
| `visit_context` | varchar(255) | YES | `NULL` | indexed |
| `service_main` | varchar(255) | YES | `NULL` |  |
| `service_sup` | varchar(255) | YES | `NULL` |  |
| `damage_location` | varchar(255) | YES | `NULL` |  |
| `severity` | varchar(255) | YES | `NULL` |  |
| `fault_severity` | varchar(10) | YES | `NULL` |  |
| `delegated_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `delegated_at` | timestamp | YES | `NULL` |  |
| `delegation_task` | varchar(10) | YES | `NULL` |  |
| `delegation_status` | varchar(20) | YES | `NULL` |  |
| `spare_part` | text | YES | `NULL` |  |
| `invoice_no` | varchar(255) | YES | `NULL` |  |
| `cost` | decimal(12,2) | YES | `NULL` |  |
| `parts_total` | decimal(12,2) | YES | `NULL` |  |
| `labor_total` | decimal(12,2) | YES | `NULL` |  |
| `cost_is_itemized` | tinyint(1) | NO | `0` |  |
| `receipt_total` | decimal(12,2) | YES | `NULL` |  |
| `variance_explanation` | text | YES | `NULL` |  |
| `reconciliation_status` | varchar(24) | YES | `NULL` | indexed |
| `reconciliation_flagged_at` | timestamp | YES | `NULL` |  |
| `cost_recorded_at` | timestamp | YES | `NULL` | indexed |
| `invoice_requested_at` | timestamp | YES | `NULL` |  |
| `invoice_requested_by` | bigint(20) unsigned | YES | `NULL` |  |
| `awaiting_invoice_since` | timestamp | YES | `NULL` |  |
| `recommendation_scheduled_for` | timestamp | YES | `NULL` |  |
| `recommendation_disposition` | varchar(20) | YES | `NULL` |  |
| `recommendation_note` | text | YES | `NULL` |  |
| `recommendation_reviewed_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `recommendation_reviewed_at` | timestamp | YES | `NULL` |  |
| `triage_route_request` | longtext | YES | `NULL` |  |
| `cost_recorded_by` | bigint(20) unsigned | YES | `NULL` |  |
| `cost_legacy_at` | timestamp | YES | `NULL` | indexed |
| `cost_legacy_note` | varchar(255) | YES | `NULL` |  |
| `cost_notes` | text | YES | `NULL` |  |
| `bill_receive` | varchar(255) | YES | `NULL` |  |
| `vendor_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `transfer_to_vendor_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `transfer_transport_method` | varchar(20) | YES | `NULL` |  |
| `maintenance_reason_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `reason_source` | varchar(16) | YES | `NULL` |  |
| `reason_matched_at` | timestamp | YES | `NULL` |  |
| `approval_status` | varchar(255) | NO | `'not_required'` | indexed |
| `approved_amount` | decimal(12,2) | YES | `NULL` |  |
| `approved_at` | timestamp | YES | `NULL` |  |
| `maintenance_tags` | longtext | YES | `NULL` |  |
| `responsible` | varchar(255) | YES | `NULL` |  |
| `approved_by` | varchar(255) | YES | `NULL` |  |
| `expected_return_date` | date | YES | `NULL` |  |
| `expected_completion_date` | date | YES | `NULL` |  |
| `expected_duration_days` | smallint(5) unsigned | YES | `NULL` |  |
| `last_checkpoint_at` | timestamp | YES | `NULL` |  |
| `maintenance_notes` | text | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |
| `deleted_at` | timestamp | YES | `NULL` | indexed |

**Foreign keys:** `vendor_id` → `vendors.id` · `delegated_by` → `users.id` · `repair_started_by` → `users.id` · `maintenance_reason_id` → `maintenance_reasons.id` · `vehicle_id` → `vehicles.id` · `contract_id` → `contracts.id` · `recommendation_reviewed_by` → `users.id` · `linked_contract_id` → `contracts.id` · `transfer_to_vendor_id` → `vendors.id` · `assigned_driver_id` → `users.id` · `ready_by` → `users.id` · `inspected_by` → `users.id` · `reviewed_by` → `users.id` · `picked_up_from_garage_by` → `users.id` · `wf_closed_by` → `users.id` · `dispatched_by` → `users.id` · `requested_by` → `users.id` · `park_arrived_by` → `users.id`

**Indexes:** `approval_status` · `assigned_driver_id` · `contract_id` *(unique)* · `cost_legacy_at` · `cost_recorded_at` · `delegated_by` · `deleted_at` · `dispatched_by` · `inspected_by` · `linked_contract_id` · `maintenance_reason_id` · `origin` · `park_arrived_by` · `picked_up_from_garage_by` · `ready_by` · `recommendation_reviewed_by` · `reconciliation_status` · `repair_location` · `repair_started_by` · `requested_by` · `request_origin` · `reviewed_by` · `row_hash` *(unique)* · `test_kind` · `transfer_to_vendor_id` · `vehicle_id,out_date` · `vendor_id` · `visit_context` · `wf_closed_by` · `workflow_status,awaiting_invoice_since` · `workflow_status` · `review_rejection_code`

### `maintenance_checkpoints`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `maintenance_id` | bigint(20) unsigned | NO |  | indexed |
| `vehicle_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `status` | varchar(30) | YES | `NULL` |  |
| `delay_reason` | varchar(40) | YES | `NULL` |  |
| `delay_reason_other` | varchar(255) | YES | `NULL` |  |
| `summary` | text | YES | `NULL` |  |
| `response` | varchar(16) | YES | `NULL` | indexed |
| `previous_expected_date` | date | YES | `NULL` |  |
| `next_expected_date` | date | YES | `NULL` |  |
| `submitted_by` | bigint(20) unsigned | YES | `NULL` |  |
| `submitted_by_name` | varchar(255) | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` | indexed |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `created_at` · `maintenance_id` · `response` · `vehicle_id`

### `maintenance_checkpoint_reminders`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `maintenance_id` | bigint(20) unsigned | NO |  | indexed |
| `vehicle_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `user_id` | bigint(20) unsigned | NO |  | indexed |
| `level` | varchar(24) | NO |  |  |
| `expected_on` | date | YES | `NULL` |  |
| `sent_on` | date | NO |  |  |
| `sent_at` | timestamp | YES | `NULL` |  |
| `responded_checkpoint_id` | bigint(20) unsigned | YES | `NULL` |  |
| `responded_at` | timestamp | YES | `NULL` | indexed |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `maintenance_id` · `user_id` · `vehicle_id` · `responded_at,sent_on` · `maintenance_id,user_id,sent_on` *(unique)*

### `maintenance_handovers`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `maintenance_id` | bigint(20) unsigned | NO |  | indexed |
| `vehicle_id` | bigint(20) unsigned | NO |  | indexed |
| `type` | varchar(10) | NO |  |  |
| `odometer_reading` | int(10) unsigned | NO |  |  |
| `odometer_ocr_reading` | int(10) unsigned | YES | `NULL` |  |
| `odometer_photo_inspection_record_id` | bigint(20) unsigned | YES | `NULL` |  |
| `fuel_level` | varchar(10) | NO |  |  |
| `exterior_condition` | varchar(255) | NO |  |  |
| `interior_condition` | varchar(255) | NO |  |  |
| `damage_findings` | longtext | YES | `NULL` |  |
| `missing_accessories` | longtext | YES | `NULL` |  |
| `notes` | text | YES | `NULL` |  |
| `signature_path` | varchar(1024) | YES | `NULL` |  |
| `signature_disk` | varchar(30) | YES | `NULL` |  |
| `actor_id` | bigint(20) unsigned | YES | `NULL` |  |
| `occurred_at` | timestamp | NO | `current_timestamp()` |  |
| `workflow_status_snapshot` | varchar(40) | YES | `NULL` |  |
| `reason` | text | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `maintenance_id` · `vehicle_id`

### `maintenance_handover_comparisons`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `maintenance_id` | bigint(20) unsigned | NO |  | indexed |
| `pause_handover_id` | bigint(20) unsigned | NO |  |  |
| `resume_handover_id` | bigint(20) unsigned | NO |  |  |
| `mileage_delta` | int(11) | YES | `NULL` |  |
| `fuel_delta` | int(11) | YES | `NULL` |  |
| `new_damages` | longtext | YES | `NULL` |  |
| `missing_accessories` | longtext | YES | `NULL` |  |
| `condition_changes` | longtext | YES | `NULL` |  |
| `exceeds_threshold` | tinyint(1) | NO | `0` |  |
| `threshold_breaches` | longtext | YES | `NULL` |  |
| `generated_at` | timestamp | NO | `current_timestamp()` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `maintenance_id`

### `maintenance_incidents`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `maintenance_id` | bigint(20) unsigned | NO |  | indexed |
| `comparison_id` | bigint(20) unsigned | YES | `NULL` |  |
| `type` | varchar(40) | NO | `'handover_discrepancy'` |  |
| `severity` | varchar(20) | YES | `NULL` |  |
| `description` | text | YES | `NULL` |  |
| `status` | varchar(20) | NO | `'open'` |  |
| `acknowledged_by` | bigint(20) unsigned | YES | `NULL` |  |
| `acknowledged_at` | timestamp | YES | `NULL` |  |
| `acknowledgement_note` | text | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `maintenance_id`

### `maintenance_invoices`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `maintenance_id` | bigint(20) unsigned | NO |  | indexed |
| `vendor_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `is_internal` | tinyint(1) | NO | `0` |  |
| `invoice_no` | varchar(120) | YES | `NULL` |  |
| `parts_total` | decimal(12,2) | NO | `0.00` |  |
| `labor_total` | decimal(12,2) | NO | `0.00` |  |
| `vat_total` | decimal(12,2) | NO | `0.00` |  |
| `discount_total` | decimal(12,2) | NO | `0.00` |  |
| `amount` | decimal(12,2) | NO | `0.00` |  |
| `receipt_total` | decimal(12,2) | YES | `NULL` |  |
| `variance_explanation` | text | YES | `NULL` |  |
| `reconciliation_status` | varchar(24) | NO | `'pending'` | indexed |
| `reconciliation_flagged_at` | timestamp | YES | `NULL` |  |
| `reconciled_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `reconciled_at` | timestamp | YES | `NULL` |  |
| `receipt_photo_disk` | varchar(20) | YES | `NULL` |  |
| `receipt_photo_key` | varchar(255) | YES | `NULL` |  |
| `notes` | text | YES | `NULL` |  |
| `recorded_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `recorded_at` | timestamp | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |
| `status` | varchar(24) | NO | `'draft'` | indexed |
| `due_date` | date | YES | `NULL` | indexed |
| `terms_days` | smallint(5) unsigned | YES | `NULL` |  |
| `approved_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `approved_by_name` | varchar(255) | YES | `NULL` |  |
| `approved_at` | timestamp | YES | `NULL` |  |
| `paid_amount` | decimal(12,2) | NO | `0.00` |  |
| `paid_at` | timestamp | YES | `NULL` |  |
| `payment_reference` | varchar(120) | YES | `NULL` |  |
| `paid_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `cancelled_at` | timestamp | YES | `NULL` |  |
| `cancellation_reason` | text | YES | `NULL` |  |

**Foreign keys:** `paid_by` → `users.id` · `maintenance_id` → `maintenances.id` · `vendor_id` → `vendors.id` · `approved_by` → `users.id` · `recorded_by` → `users.id` · `reconciled_by` → `users.id`

**Indexes:** `approved_by` · `due_date` · `maintenance_id,reconciliation_status` · `paid_by` · `reconciled_by` · `reconciliation_status` · `recorded_by` · `status` · `vendor_id`

### `maintenance_items`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `contract_id` | bigint(20) unsigned | NO |  | indexed |
| `service_name` | varchar(255) | NO |  |  |
| `cost` | decimal(12,2) | NO | `0.00` |  |
| `notes` | text | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `contract_id` → `contracts.id`

**Indexes:** `contract_id`

### `maintenance_line_items`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `maintenance_id` | bigint(20) unsigned | NO |  | indexed |
| `maintenance_invoice_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `maintenance_task_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `vehicle_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `kind` | varchar(12) | NO |  |  |
| `finding_text` | varchar(255) | YES | `NULL` |  |
| `category_key` | varchar(40) | YES | `NULL` | indexed |
| `description` | varchar(255) | NO |  |  |
| `part_number` | varchar(255) | YES | `NULL` | indexed |
| `component_catalog_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `catalog_matched_by` | varchar(12) | YES | `NULL` |  |
| `part_source` | varchar(16) | YES | `NULL` | indexed |
| `part_source_id` | bigint(20) unsigned | YES | `NULL` |  |
| `tire_brand` | varchar(255) | YES | `NULL` |  |
| `tire_dot` | varchar(20) | YES | `NULL` |  |
| `tire_tread_mm` | decimal(4,1) | YES | `NULL` |  |
| `quantity` | decimal(10,2) | NO | `1.00` |  |
| `uom` | varchar(16) | NO | `'unit'` |  |
| `unit_price` | decimal(12,2) | NO | `0.00` |  |
| `line_total` | decimal(12,2) | NO | `0.00` |  |
| `installed_on` | date | YES | `NULL` |  |
| `installed_odometer` | int(10) unsigned | YES | `NULL` |  |
| `warranty_months` | smallint(5) unsigned | YES | `NULL` |  |
| `warranty_until` | date | YES | `NULL` |  |
| `odoo_product_ref` | varchar(255) | YES | `NULL` |  |
| `odoo_external_id` | varchar(255) | YES | `NULL` |  |
| `odoo_synced_at` | timestamp | YES | `NULL` |  |
| `created_by` | bigint(20) unsigned | YES | `NULL` |  |
| `entry_source` | varchar(12) | NO | `'manual'` |  |
| `source_type` | varchar(24) | YES | `NULL` | indexed |
| `source_id` | bigint(20) unsigned | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `maintenance_invoice_id` → `maintenance_invoices.id` · `maintenance_id` → `maintenances.id` · `component_catalog_id` → `component_catalog.id` · `vehicle_id` → `vehicles.id` · `maintenance_task_id` → `maintenance_tasks.id`

**Indexes:** `category_key,kind` · `component_catalog_id` · `maintenance_id,kind` · `maintenance_invoice_id` · `maintenance_task_id,kind` · `part_number,vehicle_id` · `source_type,source_id` · `vehicle_id,category_key` · `part_source,part_source_id` · `vehicle_id,component_catalog_id`

### `maintenance_media`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `maintenance_id` | bigint(20) unsigned | NO |  | indexed |
| `maintenance_task_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `maintenance_checkpoint_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `kind` | varchar(20) | NO | `'video'` |  |
| `disk` | varchar(30) | NO | `'s3'` |  |
| `s3_key` | varchar(1024) | NO |  |  |
| `content_type` | varchar(100) | YES | `NULL` |  |
| `original_name` | varchar(255) | YES | `NULL` |  |
| `file_size` | bigint(20) unsigned | YES | `NULL` |  |
| `note` | varchar(500) | YES | `NULL` |  |
| `uploaded_by` | bigint(20) unsigned | YES | `NULL` |  |
| `uploaded_by_name` | varchar(191) | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |
| `vehicle_component_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `component_event_id` | bigint(20) unsigned | YES | `NULL` | indexed |

**Foreign keys:** `vehicle_component_id` → `vehicle_components.id` · `component_event_id` → `component_events.id`

**Indexes:** `component_event_id` · `maintenance_checkpoint_id` · `maintenance_id` · `maintenance_task_id` · `vehicle_component_id`

### `maintenance_reasons`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `sheet_ref` | int(10) unsigned | YES | `NULL` |  |
| `reason_en` | varchar(255) | NO |  | unique |
| `reason_ar` | varchar(255) | YES | `NULL` |  |
| `status_raw` | varchar(255) | YES | `NULL` |  |
| `level` | varchar(255) | NO | `'routine'` | indexed |
| `explanation` | text | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `level` · `reason_en` *(unique)*

### `maintenance_required_parts`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `maintenance_id` | bigint(20) unsigned | NO |  | indexed |
| `vehicle_id` | bigint(20) unsigned | NO |  | indexed |
| `maintenance_task_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `finding_key` | varchar(191) | YES | `NULL` | indexed |
| `finding_text` | varchar(500) | YES | `NULL` |  |
| `component_catalog_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `catalog_matched_by` | varchar(12) | YES | `NULL` |  |
| `part_name` | varchar(255) | NO |  |  |
| `notes` | text | YES | `NULL` |  |
| `quantity` | decimal(10,2) | NO | `1.00` |  |
| `priority` | varchar(12) | YES | `NULL` |  |
| `status` | varchar(12) | NO | `'pending'` | indexed |
| `recorded_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `recorded_by_name` | varchar(255) | YES | `NULL` |  |
| `recorded_at` | timestamp | YES | `NULL` |  |
| `actioned_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `actioned_by_name` | varchar(255) | YES | `NULL` |  |
| `actioned_at` | timestamp | YES | `NULL` |  |
| `dismissal_reason` | text | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `component_catalog_id` → `component_catalog.id` · `vehicle_id` → `vehicles.id` · `actioned_by` → `users.id` · `recorded_by` → `users.id` · `maintenance_task_id` → `maintenance_tasks.id` · `maintenance_id` → `maintenances.id`

**Indexes:** `actioned_by` · `finding_key` · `maintenance_id,status` · `maintenance_task_id` · `recorded_by` · `status` · `vehicle_id,status` · `component_catalog_id,status`

### `maintenance_responsibles`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `maintenance_id` | bigint(20) unsigned | NO |  | indexed |
| `user_id` | bigint(20) unsigned | NO |  |  |
| `added_by` | bigint(20) unsigned | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `maintenance_id` · `maintenance_id,user_id` *(unique)*

### `maintenance_signatures`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `maintenance_id` | bigint(20) unsigned | NO |  | indexed |
| `vehicle_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `occurred_at` | date | YES | `NULL` |  |
| `signature` | varchar(24) | NO |  | indexed |
| `source` | varchar(12) | NO | `'derived'` |  |
| `is_exposure` | tinyint(1) | NO | `0` |  |
| `matched_terms` | longtext | YES | `NULL` |  |
| `classifier_version` | varchar(20) | NO |  | indexed |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `maintenance_id` → `maintenances.id`

**Indexes:** `classifier_version` · `signature,occurred_at` · `maintenance_id,signature` *(unique)* · `vehicle_id,signature,occurred_at`

### `maintenance_swaps`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `original_vehicle_id` | bigint(20) unsigned | NO |  | indexed |
| `original_plate` | varchar(255) | YES | `NULL` |  |
| `original_car` | varchar(255) | YES | `NULL` |  |
| `original_contract_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `original_contract_no` | varchar(255) | YES | `NULL` |  |
| `tenant_name` | varchar(255) | YES | `NULL` |  |
| `reason` | varchar(255) | YES | `NULL` |  |
| `replacement_vehicle_id` | bigint(20) unsigned | NO |  | indexed |
| `replacement_plate` | varchar(255) | YES | `NULL` |  |
| `replacement_car` | varchar(255) | YES | `NULL` |  |
| `status` | varchar(255) | NO | `'active'` |  |
| `assigned_by` | varchar(255) | YES | `NULL` |  |
| `released_by` | varchar(255) | YES | `NULL` |  |
| `released_at` | timestamp | YES | `NULL` |  |
| `notes` | text | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `original_contract_id` · `original_vehicle_id` · `original_vehicle_id,status` · `replacement_vehicle_id` · `replacement_vehicle_id,status`

### `maintenance_tasks`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `maintenance_id` | bigint(20) unsigned | NO |  | indexed |
| `maintenance_invoice_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `vehicle_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `symptom` | varchar(255) | NO |  |  |
| `quantity` | smallint(5) unsigned | NO | `1` |  |
| `kind` | varchar(20) | NO | `'fault'` | indexed |
| `fault_catalog_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `service_catalog_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `inspection_type_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `damage_catalog_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `classification_source` | varchar(20) | NO | `'resolver'` |  |
| `needs_review` | tinyint(1) | NO | `0` | indexed |
| `category_key` | varchar(60) | YES | `NULL` |  |
| `source` | varchar(20) | NO | `'inspector'` |  |
| `severity` | varchar(10) | YES | `NULL` |  |
| `root_cause_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `root_cause` | varchar(191) | YES | `NULL` |  |
| `notes` | text | YES | `NULL` |  |
| `resolution_note` | text | YES | `NULL` |  |
| `claimed_outcome` | varchar(24) | YES | `NULL` | indexed |
| `claimed_outcome_by` | bigint(20) unsigned | YES | `NULL` |  |
| `claimed_outcome_at` | timestamp | YES | `NULL` |  |
| `verification_method` | varchar(24) | YES | `NULL` |  |
| `verified_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `verified_at` | timestamp | YES | `NULL` |  |
| `verification_result` | varchar(24) | YES | `NULL` |  |
| `verification_note` | text | YES | `NULL` |  |
| `no_fault_found` | tinyint(1) | YES | `NULL` |  |
| `status` | varchar(20) | NO | `'pending'` | indexed |
| `confirmation_status` | varchar(20) | YES | `NULL` | indexed |
| `confirmation_note` | text | YES | `NULL` |  |
| `confirmed_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `confirmed_at` | timestamp | YES | `NULL` |  |
| `recurrence_flagged` | tinyint(1) | NO | `0` |  |
| `recurrence_previous_task_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `derived_from_task_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `repair_gate` | varchar(20) | YES | `NULL` | indexed |
| `repair_gate_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `repair_gate_at` | timestamp | YES | `NULL` |  |
| `repair_gate_note` | text | YES | `NULL` |  |
| `current_vendor_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `identified_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `identified_at` | timestamp | YES | `NULL` |  |
| `started_at` | timestamp | YES | `NULL` |  |
| `resolved_at` | timestamp | YES | `NULL` |  |
| `resolved_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `reinspection_failures` | int(10) unsigned | NO | `0` |  |
| `last_failed_vendor_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `last_failed_at` | timestamp | YES | `NULL` |  |
| `marked_incorrect_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `marked_incorrect_at` | timestamp | YES | `NULL` |  |
| `incorrect_reason` | varchar(2000) | YES | `NULL` |  |
| `parts_cost` | decimal(12,2) | NO | `0.00` |  |
| `labor_cost` | decimal(12,2) | NO | `0.00` |  |
| `repair_hours` | decimal(6,2) | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `fault_catalog_id` → `fault_catalog.id` · `resolved_by` → `users.id` · `maintenance_id` → `maintenances.id` · `derived_from_task_id` → `maintenance_tasks.id` · `repair_gate_by` → `users.id` · `last_failed_vendor_id` → `vendors.id` · `vehicle_id` → `vehicles.id` · `damage_catalog_id` → `damage_catalog.id` · `recurrence_previous_task_id` → `maintenance_tasks.id` · `inspection_type_id` → `inspection_types.id` · `service_catalog_id` → `service_catalog.id` · `current_vendor_id` → `vendors.id` · `marked_incorrect_by` → `users.id` · `identified_by` → `users.id` · `root_cause_id` → `fault_causes.id` · `confirmed_by` → `users.id` · `maintenance_invoice_id` → `maintenance_invoices.id`

**Indexes:** `claimed_outcome` · `confirmation_status` · `confirmed_by` · `current_vendor_id,status` · `damage_catalog_id` · `derived_from_task_id` · `fault_catalog_id` · `identified_by` · `inspection_type_id` · `kind` · `last_failed_vendor_id` · `maintenance_id,status` · `maintenance_invoice_id` · `marked_incorrect_by` · `needs_review` · `recurrence_previous_task_id` · `repair_gate_by` · `repair_gate` · `resolved_by` · `root_cause_id` · `service_catalog_id` · `status` · `vehicle_id,status` · `verified_by`

### `maintenance_task_actions`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `maintenance_task_id` | bigint(20) unsigned | NO |  | indexed |
| `maintenance_id` | bigint(20) unsigned | YES | `NULL` |  |
| `vehicle_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `action_catalog_id` | bigint(20) unsigned | NO |  | indexed |
| `sequence` | int(10) unsigned | NO | `1` |  |
| `performed_by` | bigint(20) unsigned | YES | `NULL` |  |
| `performed_by_name` | varchar(255) | YES | `NULL` |  |
| `vendor_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `performed_at` | timestamp | YES | `NULL` |  |
| `note` | text | YES | `NULL` |  |
| `line_item_id` | bigint(20) unsigned | YES | `NULL` |  |
| `recorded_via` | varchar(24) | NO | `'workflow'` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `action_catalog_id,performed_at` · `maintenance_task_id,sequence` · `vehicle_id,performed_at` · `vendor_id`

### `maintenance_task_assignments`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `maintenance_task_id` | bigint(20) unsigned | NO |  | indexed |
| `vendor_id` | bigint(20) unsigned | NO |  | indexed |
| `assigned_at` | timestamp | NO | `current_timestamp()` |  |
| `arrived_at` | datetime | YES | `NULL` |  |
| `work_started_at` | datetime | YES | `NULL` |  |
| `released_at` | timestamp | YES | `NULL` |  |
| `outcome` | varchar(20) | YES | `NULL` |  |
| `reason` | text | YES | `NULL` |  |
| `labor_hours` | decimal(6,2) | YES | `NULL` |  |
| `labor_recorded_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `labor_recorded_at` | datetime | YES | `NULL` |  |
| `assigned_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `released_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `released_by` → `users.id` · `maintenance_task_id` → `maintenance_tasks.id` · `labor_recorded_by` → `users.id` · `assigned_by` → `users.id` · `vendor_id` → `vendors.id`

**Indexes:** `assigned_by` · `labor_recorded_by` · `released_by` · `vendor_id,released_at` · `maintenance_task_id,assigned_at`

### `maintenance_task_locations`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `maintenance_task_id` | bigint(20) unsigned | NO |  | indexed |
| `vehicle_location_id` | bigint(20) unsigned | NO |  | indexed |
| `sort_order` | smallint(5) unsigned | NO | `0` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `maintenance_task_id` → `maintenance_tasks.id` · `vehicle_location_id` → `vehicle_locations.id`

**Indexes:** `vehicle_location_id` · `maintenance_task_id,vehicle_location_id` *(unique)*

### `maintenance_temporary_releases`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `maintenance_id` | bigint(20) unsigned | NO |  | indexed |
| `vehicle_id` | bigint(20) unsigned | NO |  | indexed |
| `reason` | varchar(30) | NO |  |  |
| `reason_note` | text | YES | `NULL` |  |
| `stage` | varchar(32) | NO | `'out_dispatch'` | indexed |
| `destination` | varchar(160) | YES | `NULL` |  |
| `taken_by` | varchar(120) | NO |  |  |
| `released_by` | bigint(20) unsigned | YES | `NULL` |  |
| `released_at` | timestamp | NO | `current_timestamp()` |  |
| `odometer_out` | int(10) unsigned | YES | `NULL` |  |
| `workflow_status_snapshot` | varchar(40) | YES | `NULL` |  |
| `vendor_id_snapshot` | bigint(20) unsigned | YES | `NULL` |  |
| `garage_snapshot` | varchar(190) | YES | `NULL` |  |
| `return_vendor_id` | bigint(20) unsigned | YES | `NULL` |  |
| `return_garage` | varchar(190) | YES | `NULL` |  |
| `out_driver_id` | bigint(20) unsigned | YES | `NULL` |  |
| `return_driver_id` | bigint(20) unsigned | YES | `NULL` |  |
| `out_assigned_at` | datetime | YES | `NULL` |  |
| `out_started_at` | datetime | YES | `NULL` |  |
| `arrived_at` | datetime | YES | `NULL` |  |
| `return_requested_at` | datetime | YES | `NULL` |  |
| `return_assigned_at` | datetime | YES | `NULL` |  |
| `return_started_at` | datetime | YES | `NULL` |  |
| `returned_at` | timestamp | YES | `NULL` |  |
| `returned_by` | bigint(20) unsigned | YES | `NULL` |  |
| `odometer_in` | int(10) unsigned | YES | `NULL` |  |
| `distance_km` | int(11) | YES | `NULL` |  |
| `return_note` | text | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `maintenance_id` · `vehicle_id` · `stage`

A release is a ROUND TRIP out of the workshop and back, and `stage` is where it stands:
`out_dispatch → out_assigned → out_transit → at_destination → return_dispatch → return_assigned →
return_transit`, then the row CLOSES (`returned_at` set, the ticket's `active_temporary_release_id`
cleared). There is no "done" stage — `returned_at` is the single answer to "is the car out?".
`odometer_out` is nullable because the reading is taken when a driver physically collects the car, not
when the release is approved; a release cancelled before the car moved therefore has neither reading.
The ticket's `workflow_status` never changes for any of this — the board reads the lane from `stage`
(see `MaintenanceTemporaryRelease::STAGE_LANES`). The `*_at` legs are `datetime`, not `timestamp`, so
MariaDB cannot attach `ON UPDATE CURRENT_TIMESTAMP` and silently rewrite a stored moment.

### `maintenance_tombstones`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `row_hash` | varchar(255) | NO |  | unique |
| `vehicle_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `origin` | varchar(255) | NO | `'sheet'` |  |
| `out_date` | date | YES | `NULL` |  |
| `payload` | longtext | NO |  |  |
| `display` | longtext | NO |  |  |
| `note` | varchar(255) | YES | `NULL` |  |
| `created_by` | bigint(20) unsigned | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `row_hash` *(unique)* · `vehicle_id`

### `maintenance_watchers`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `maintenance_id` | bigint(20) unsigned | NO |  | indexed |
| `user_id` | bigint(20) unsigned | NO |  | indexed |
| `added_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `reason` | varchar(30) | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `maintenance_id` → `maintenances.id` · `added_by` → `users.id` · `user_id` → `users.id`

**Indexes:** `added_by` · `maintenance_id,user_id` *(unique)* · `user_id`

### `media`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `model_type` | varchar(255) | NO |  | indexed |
| `model_id` | bigint(20) unsigned | NO |  |  |
| `uuid` | char(36) | YES | `NULL` | unique |
| `collection_name` | varchar(255) | NO |  |  |
| `name` | varchar(255) | NO |  |  |
| `file_name` | varchar(255) | NO |  |  |
| `mime_type` | varchar(255) | YES | `NULL` |  |
| `disk` | varchar(255) | NO |  |  |
| `conversions_disk` | varchar(255) | YES | `NULL` |  |
| `size` | bigint(20) unsigned | NO |  |  |
| `manipulations` | longtext | NO |  |  |
| `custom_properties` | longtext | NO |  |  |
| `generated_conversions` | longtext | NO |  |  |
| `responsive_images` | longtext | NO |  |  |
| `order_column` | int(10) unsigned | YES | `NULL` | indexed |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `model_type,model_id` · `order_column` · `uuid` *(unique)*

### `migrations`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | int(10) unsigned | NO |  | PK, auto |
| `migration` | varchar(255) | NO |  |  |
| `batch` | int(11) | NO |  |  |

### `mileage_overrides`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `contract_id` | bigint(20) unsigned | NO |  | indexed |
| `field` | varchar(16) | NO |  |  |
| `original_value` | int(10) unsigned | YES | `NULL` |  |
| `corrected_value` | int(10) unsigned | YES | `NULL` |  |
| `note` | text | YES | `NULL` |  |
| `user_id` | bigint(20) unsigned | YES | `NULL` |  |
| `user_name` | varchar(255) | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `contract_id` → `contracts.id`

**Indexes:** `contract_id,field` *(unique)*

### `model_has_permissions`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `permission_id` | bigint(20) unsigned | NO |  | PK |
| `model_type` | varchar(255) | NO |  | PK |
| `model_id` | bigint(20) unsigned | NO |  | PK |

**Foreign keys:** `permission_id` → `permissions.id`

**Indexes:** `model_id,model_type`

### `model_has_roles`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `role_id` | bigint(20) unsigned | NO |  | PK |
| `model_type` | varchar(255) | NO |  | PK |
| `model_id` | bigint(20) unsigned | NO |  | PK |

**Foreign keys:** `role_id` → `roles.id`

**Indexes:** `model_id,model_type`

### `notifications`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | char(36) | NO |  | PK |
| `type` | varchar(255) | NO |  |  |
| `notifiable_type` | varchar(255) | NO |  | indexed |
| `notifiable_id` | bigint(20) unsigned | NO |  |  |
| `data` | text | NO |  |  |
| `read_at` | timestamp | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `notifiable_type,notifiable_id` · `notifiable_type,notifiable_id,read_at`

### `odometer_block_events`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `maintenance_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `vehicle_id` | bigint(20) unsigned | YES | `NULL` |  |
| `stage_key` | varchar(32) | NO |  |  |
| `status` | varchar(32) | NO |  |  |
| `previous` | int(10) unsigned | YES | `NULL` |  |
| `reading` | int(11) | NO |  |  |
| `delta` | int(11) | YES | `NULL` |  |
| `note` | text | YES | `NULL` |  |
| `actor_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `created_at` | timestamp | YES | `NULL` | indexed |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `maintenance_id` → `maintenances.id` · `actor_id` → `users.id`

**Indexes:** `actor_id` · `created_at` · `maintenance_id`

### `odometer_change_requests`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `vehicle_id` | bigint(20) unsigned | NO |  | indexed |
| `source` | varchar(32) | NO | `'manual_edit'` | indexed |
| `maintenance_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `stage_key` | varchar(40) | YES | `NULL` |  |
| `previous_odometer` | bigint(20) unsigned | YES | `NULL` |  |
| `requested_odometer` | bigint(20) unsigned | NO |  |  |
| `delta` | bigint(20) | NO |  |  |
| `note` | text | NO |  |  |
| `workflow_stage` | varchar(255) | YES | `NULL` |  |
| `status` | varchar(255) | NO | `'pending'` | indexed |
| `requested_by_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `requested_by` | varchar(255) | YES | `NULL` |  |
| `reviewed_by_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `reviewed_by` | varchar(255) | YES | `NULL` |  |
| `reviewed_at` | timestamp | YES | `NULL` |  |
| `review_note` | text | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `reviewed_by_id` → `users.id` · `requested_by_id` → `users.id` · `maintenance_id` → `maintenances.id` · `vehicle_id` → `vehicles.id`

**Indexes:** `maintenance_id` · `requested_by_id` · `reviewed_by_id` · `source` · `status` · `vehicle_id`

### `oil_recall_tasks`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `contract_oil_decision_id` | bigint(20) unsigned | NO |  | unique |
| `contract_id` | bigint(20) unsigned | NO |  | indexed |
| `vehicle_id` | bigint(20) unsigned | NO |  | indexed |
| `status` | varchar(16) | NO | `'open'` | indexed |
| `reason_code` | varchar(64) | NO | `'oil_tolerance_exceeded_before_return'` |  |
| `customer_reading` | int(10) unsigned | YES | `NULL` |  |
| `customer_reading_on` | date | YES | `NULL` |  |
| `oil_limit` | int(10) unsigned | YES | `NULL` |  |
| `allowed_max` | int(10) unsigned | YES | `NULL` |  |
| `expected_return_odometer` | int(10) unsigned | YES | `NULL` |  |
| `remaining_days` | smallint(5) unsigned | YES | `NULL` |  |
| `created_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `created_by_name` | varchar(255) | YES | `NULL` |  |
| `decided_at` | timestamp | YES | `NULL` |  |
| `assigned_user_ids` | longtext | YES | `NULL` |  |
| `claimed_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `claimed_at` | timestamp | YES | `NULL` |  |
| `note` | text | YES | `NULL` |  |
| `outcome_note` | text | YES | `NULL` |  |
| `completed_at` | timestamp | YES | `NULL` |  |
| `completed_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `completed_by` → `users.id` · `vehicle_id` → `vehicles.id` · `claimed_by` → `users.id` · `created_by` → `users.id` · `contract_oil_decision_id` → `contract_oil_decisions.id` · `contract_id` → `contracts.id`

**Indexes:** `claimed_by` · `completed_by` · `contract_id` · `contract_oil_decision_id` *(unique)* · `created_by` · `status,id` · `vehicle_id,status`

### `ontology_edges`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `from_node_id` | bigint(20) unsigned | NO |  | indexed |
| `to_node_id` | bigint(20) unsigned | NO |  | indexed |
| `relation` | varchar(24) | NO |  | indexed |
| `weight` | tinyint(3) unsigned | NO | `50` |  |
| `confidence` | tinyint(3) unsigned | NO | `70` |  |
| `source` | varchar(12) | NO | `'ai'` | indexed |
| `observed_count` | int(10) unsigned | NO | `0` |  |
| `observed_rate` | tinyint(3) unsigned | NO | `0` |  |
| `last_observed_at` | timestamp | YES | `NULL` |  |
| `scope_key` | varchar(120) | NO | `'*'` | indexed |
| `note` | varchar(500) | YES | `NULL` |  |
| `is_active` | tinyint(1) | NO | `1` | indexed |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `to_node_id` → `ontology_nodes.id` · `from_node_id` → `ontology_nodes.id`

**Indexes:** `is_active` · `relation` · `to_node_id,relation` · `scope_key` · `source` · `from_node_id,relation,weight` · `from_node_id,to_node_id,relation,scope_key` *(unique)*

### `ontology_feedback`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `action` | varchar(20) | NO |  | indexed |
| `subject_type` | varchar(60) | YES | `NULL` | indexed |
| `subject_id` | bigint(20) unsigned | YES | `NULL` |  |
| `finding_keyword_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `before` | longtext | YES | `NULL` |  |
| `after` | longtext | YES | `NULL` |  |
| `query_text` | text | YES | `NULL` |  |
| `match_score` | tinyint(3) unsigned | YES | `NULL` |  |
| `maintenance_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `vehicle_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `reason` | varchar(500) | YES | `NULL` |  |
| `context` | varchar(40) | YES | `NULL` | indexed |
| `user_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `applied_at` | timestamp | YES | `NULL` | indexed |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `maintenance_id` → `maintenances.id` · `finding_keyword_id` → `finding_keywords.id` · `vehicle_id` → `vehicles.id` · `user_id` → `users.id`

**Indexes:** `action` · `applied_at` · `finding_keyword_id,action` · `context,action` · `context` · `maintenance_id` · `subject_type,subject_id` · `user_id` · `vehicle_id`

### `ontology_nodes`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `type` | varchar(20) | NO |  | indexed |
| `key` | varchar(191) | NO |  | indexed |
| `label` | varchar(191) | NO |  |  |
| `label_ar` | varchar(191) | YES | `NULL` |  |
| `finding_keyword_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `make` | varchar(60) | YES | `NULL` | indexed |
| `model` | varchar(60) | YES | `NULL` |  |
| `generation` | varchar(60) | YES | `NULL` |  |
| `engine` | varchar(60) | YES | `NULL` |  |
| `scope_key` | varchar(120) | NO | `'*'` | indexed |
| `source` | varchar(12) | NO | `'ai'` | indexed |
| `confidence` | tinyint(3) unsigned | NO | `70` |  |
| `description` | varchar(500) | YES | `NULL` |  |
| `is_active` | tinyint(1) | NO | `1` | indexed |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `finding_keyword_id` → `finding_keywords.id`

**Indexes:** `finding_keyword_id` · `is_active` · `key` · `make` · `scope_key` · `source` · `type` · `type,key,scope_key` *(unique)*

### `part_investigations`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `type` | varchar(20) | NO |  | indexed |
| `priority` | varchar(10) | NO | `'medium'` |  |
| `status` | varchar(20) | NO | `'open'` | indexed |
| `vehicle_id` | bigint(20) unsigned | NO |  | indexed |
| `part_purchase_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `previous_purchase_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `maintenance_task_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `previous_task_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `reason_code` | varchar(40) | YES | `NULL` |  |
| `reason_note` | text | YES | `NULL` |  |
| `context` | longtext | YES | `NULL` |  |
| `opened_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `opened_by_name` | varchar(255) | YES | `NULL` |  |
| `opened_at` | timestamp | YES | `NULL` |  |
| `reason_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `reason_by_name` | varchar(255) | YES | `NULL` |  |
| `reason_at` | timestamp | YES | `NULL` |  |
| `resolved_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `resolved_by_name` | varchar(255) | YES | `NULL` |  |
| `resolved_at` | timestamp | YES | `NULL` |  |
| `resolution` | varchar(20) | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `maintenance_task_id` → `maintenance_tasks.id` · `previous_task_id` → `maintenance_tasks.id` · `previous_purchase_id` → `part_purchases.id` · `vehicle_id` → `vehicles.id` · `part_purchase_id` → `part_purchases.id` · `resolved_by` → `users.id` · `opened_by` → `users.id` · `reason_by` → `users.id`

**Indexes:** `maintenance_task_id` · `opened_by` · `part_purchase_id` · `previous_purchase_id` · `previous_task_id` · `reason_by` · `resolved_by` · `status` · `status,priority` · `type` · `vehicle_id,status`

### `part_invoices`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `vendor_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `supplier_name` | varchar(255) | YES | `NULL` |  |
| `invoice_no` | varchar(120) | YES | `NULL` | indexed |
| `invoice_date` | date | YES | `NULL` |  |
| `currency` | varchar(3) | NO | `'AED'` |  |
| `subtotal` | decimal(12,2) | NO | `0.00` |  |
| `tax_amount` | decimal(12,2) | NO | `0.00` |  |
| `discount_amount` | decimal(12,2) | NO | `0.00` |  |
| `total_amount` | decimal(12,2) | NO | `0.00` |  |
| `stated_total` | decimal(12,2) | YES | `NULL` |  |
| `variance_explanation` | text | YES | `NULL` |  |
| `photo_disk` | varchar(20) | YES | `NULL` |  |
| `photo_key` | varchar(255) | YES | `NULL` |  |
| `notes` | text | YES | `NULL` |  |
| `recorded_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `recorded_by_name` | varchar(255) | YES | `NULL` |  |
| `recorded_at` | timestamp | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |
| `status` | varchar(24) | NO | `'draft'` | indexed |
| `due_date` | date | YES | `NULL` | indexed |
| `terms_days` | smallint(5) unsigned | YES | `NULL` |  |
| `approved_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `approved_by_name` | varchar(255) | YES | `NULL` |  |
| `approved_at` | timestamp | YES | `NULL` |  |
| `paid_amount` | decimal(12,2) | NO | `0.00` |  |
| `paid_at` | timestamp | YES | `NULL` |  |
| `payment_reference` | varchar(120) | YES | `NULL` |  |
| `paid_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `cancelled_at` | timestamp | YES | `NULL` |  |
| `cancellation_reason` | text | YES | `NULL` |  |

**Foreign keys:** `approved_by` → `users.id` · `vendor_id` → `vendors.id` · `recorded_by` → `users.id` · `paid_by` → `users.id`

**Indexes:** `approved_by` · `due_date` · `invoice_no` · `paid_by` · `recorded_by` · `status` · `vendor_id,invoice_date`

### `part_purchases`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `part_request_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `rfq_line_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `supplier_quote_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `po_number` | varchar(40) | YES | `NULL` |  |
| `part_invoice_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `vehicle_id` | bigint(20) unsigned | NO |  | indexed |
| `maintenance_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `maintenance_task_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `part_name` | varchar(255) | NO |  |  |
| `component_catalog_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `catalog_matched_by` | varchar(12) | YES | `NULL` |  |
| `part_name_key` | varchar(191) | YES | `NULL` |  |
| `part_number` | varchar(255) | YES | `NULL` |  |
| `category_key` | varchar(60) | YES | `NULL` |  |
| `part_class` | varchar(12) | YES | `NULL` |  |
| `purchase_source` | varchar(12) | NO |  | indexed |
| `source_vendor_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `source_name` | varchar(255) | YES | `NULL` |  |
| `repair_location` | varchar(10) | YES | `NULL` |  |
| `purchase_price` | decimal(12,2) | NO |  |  |
| `currency` | varchar(3) | NO | `'AED'` |  |
| `quantity` | decimal(10,2) | NO | `1.00` |  |
| `purchased_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `purchased_by_name` | varchar(255) | YES | `NULL` |  |
| `purchased_at` | timestamp | YES | `NULL` | indexed |
| `expected_delivery_date` | date | YES | `NULL` |  |
| `delivered_at` | timestamp | YES | `NULL` |  |
| `installed_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `installed_by_name` | varchar(255) | YES | `NULL` |  |
| `installed_at` | timestamp | YES | `NULL` |  |
| `installed_odometer` | int(10) unsigned | YES | `NULL` |  |
| `result` | varchar(12) | NO | `'pending'` |  |
| `maintenance_line_item_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `requires_review` | tinyint(1) | NO | `0` |  |
| `duplicate_of_purchase_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `notes` | text | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `rfq_line_id` → `rfq_lines.id` · `maintenance_line_item_id` → `maintenance_line_items.id` · `purchased_by` → `users.id` · `maintenance_id` → `maintenances.id` · `vehicle_id` → `vehicles.id` · `part_request_id` → `part_requests.id` · `installed_by` → `users.id` · `supplier_quote_id` → `supplier_quotes.id` · `part_invoice_id` → `part_invoices.id` · `duplicate_of_purchase_id` → `part_purchases.id` · `source_vendor_id` → `vendors.id` · `maintenance_task_id` → `maintenance_tasks.id` · `component_catalog_id` → `component_catalog.id`

**Indexes:** `component_catalog_id` · `duplicate_of_purchase_id` · `installed_by` · `maintenance_id` · `maintenance_line_item_id` · `maintenance_task_id` · `part_invoice_id` · `part_request_id` · `purchased_at` · `purchased_by` · `purchase_source` · `rfq_line_id` · `source_vendor_id` · `supplier_quote_id` · `vehicle_id,part_number` · `vehicle_id,component_catalog_id` · `vehicle_id,part_name_key`

### `part_requests`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `source` | varchar(12) | NO |  | indexed |
| `status` | varchar(20) | NO | `'requested'` | indexed |
| `vehicle_id` | bigint(20) unsigned | NO |  | indexed |
| `customer_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `maintenance_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `maintenance_task_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `part_name` | varchar(255) | NO |  |  |
| `component_catalog_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `catalog_matched_by` | varchar(12) | YES | `NULL` |  |
| `part_name_key` | varchar(191) | YES | `NULL` |  |
| `part_number` | varchar(255) | YES | `NULL` | indexed |
| `category_key` | varchar(60) | YES | `NULL` |  |
| `part_class` | varchar(12) | YES | `NULL` |  |
| `repair_location` | varchar(10) | YES | `NULL` |  |
| `quantity` | decimal(10,2) | NO | `1.00` |  |
| `reason` | text | NO |  |  |
| `estimated_price` | decimal(12,2) | YES | `NULL` |  |
| `currency` | varchar(3) | NO | `'AED'` |  |
| `notes` | text | YES | `NULL` |  |
| `requested_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `requested_by_name` | varchar(255) | YES | `NULL` |  |
| `requested_at` | timestamp | YES | `NULL` |  |
| `reviewed_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `reviewed_by_name` | varchar(255) | YES | `NULL` |  |
| `reviewed_at` | timestamp | YES | `NULL` |  |
| `review_notes` | text | YES | `NULL` |  |
| `approved_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `approved_by_name` | varchar(255) | YES | `NULL` |  |
| `approved_at` | timestamp | YES | `NULL` |  |
| `rejected_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `rejected_by_name` | varchar(255) | YES | `NULL` |  |
| `rejected_at` | timestamp | YES | `NULL` |  |
| `rejection_reason` | text | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `maintenance_task_id` → `maintenance_tasks.id` · `vehicle_id` → `vehicles.id` · `maintenance_id` → `maintenances.id` · `reviewed_by` → `users.id` · `customer_id` → `customers.id` · `requested_by` → `users.id` · `component_catalog_id` → `component_catalog.id` · `rejected_by` → `users.id` · `approved_by` → `users.id`

**Indexes:** `approved_by` · `component_catalog_id` · `customer_id` · `maintenance_id` · `maintenance_task_id` · `part_number,vehicle_id` · `rejected_by` · `requested_by` · `reviewed_by` · `source,status` · `status` · `vehicle_id,status` · `vehicle_id,component_catalog_id` · `vehicle_id,part_name_key`

### `part_request_required_part`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `part_request_id` | bigint(20) unsigned | NO |  | indexed |
| `maintenance_required_part_id` | bigint(20) unsigned | NO |  | indexed |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `part_request_id` → `part_requests.id` · `maintenance_required_part_id` → `maintenance_required_parts.id`

**Indexes:** `maintenance_required_part_id` · `part_request_id,maintenance_required_part_id` *(unique)*

### `part_returns`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `part_purchase_id` | bigint(20) unsigned | NO |  | indexed |
| `vehicle_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `maintenance_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `maintenance_task_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `quantity` | decimal(10,2) | NO | `1.00` |  |
| `reason_code` | varchar(24) | NO |  | indexed |
| `reason_note` | text | YES | `NULL` |  |
| `refund_amount` | decimal(12,2) | NO | `0.00` |  |
| `restocking_fee` | decimal(12,2) | NO | `0.00` |  |
| `currency` | varchar(3) | NO | `'AED'` |  |
| `status` | varchar(12) | NO | `'requested'` |  |
| `rejection_reason` | text | YES | `NULL` |  |
| `credit_line_item_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `returned_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `returned_by_name` | varchar(255) | YES | `NULL` |  |
| `returned_at` | timestamp | YES | `NULL` |  |
| `settled_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `settled_by_name` | varchar(255) | YES | `NULL` |  |
| `settled_at` | timestamp | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `maintenance_task_id` → `maintenance_tasks.id` · `vehicle_id` → `vehicles.id` · `maintenance_id` → `maintenances.id` · `settled_by` → `users.id` · `credit_line_item_id` → `maintenance_line_items.id` · `returned_by` → `users.id` · `part_purchase_id` → `part_purchases.id`

**Indexes:** `credit_line_item_id` · `maintenance_id,status` · `maintenance_task_id` · `part_purchase_id,status` · `reason_code` · `returned_by` · `settled_by` · `vehicle_id`

### `part_rfqs`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `status` | varchar(16) | NO | `'open'` | indexed |
| `needed_by_date` | date | YES | `NULL` |  |
| `notes` | text | YES | `NULL` |  |
| `opened_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `opened_by_name` | varchar(255) | YES | `NULL` |  |
| `opened_at` | timestamp | YES | `NULL` |  |
| `closed_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `closed_by_name` | varchar(255) | YES | `NULL` |  |
| `closed_at` | timestamp | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `opened_by` → `users.id` · `closed_by` → `users.id`

**Indexes:** `closed_by` · `opened_by` · `status`

### `password_reset_tokens`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `email` | varchar(255) | NO |  | PK |
| `token` | varchar(255) | NO |  |  |
| `created_at` | timestamp | YES | `NULL` |  |

### `payments`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `payment_ref` | varchar(255) | YES | `NULL` | unique |
| `contract_id` | bigint(20) unsigned | NO |  | indexed |
| `invoice_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `customer_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `amount` | decimal(14,2) | NO | `0.00` |  |
| `paid_on` | date | YES | `NULL` |  |
| `method` | varchar(255) | YES | `NULL` |  |
| `reference` | varchar(255) | YES | `NULL` |  |
| `notes` | text | YES | `NULL` |  |
| `recorded_by` | varchar(255) | YES | `NULL` |  |
| `origin` | varchar(255) | NO | `'manual'` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `invoice_id` → `invoices.id` · `customer_id` → `customers.id` · `contract_id` → `contracts.id`

**Indexes:** `contract_id` · `customer_id` · `invoice_id` · `payment_ref` *(unique)*

### `payment_allocations`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `supplier_payment_id` | bigint(20) unsigned | NO |  | indexed |
| `document_type` | varchar(24) | NO |  | indexed |
| `document_id` | bigint(20) unsigned | NO |  |  |
| `amount` | decimal(12,2) | NO |  |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `supplier_payment_id` → `supplier_payments.id`

**Indexes:** `document_type,document_id` · `supplier_payment_id,document_type,document_id` *(unique)*

### `permissions`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `name` | varchar(255) | NO |  | indexed |
| `guard_name` | varchar(255) | NO |  |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `name,guard_name` *(unique)*

### `personal_access_tokens`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `tokenable_type` | varchar(255) | NO |  | indexed |
| `tokenable_id` | bigint(20) unsigned | NO |  |  |
| `name` | text | NO |  |  |
| `token` | varchar(64) | NO |  | unique |
| `abilities` | text | YES | `NULL` |  |
| `last_used_at` | timestamp | YES | `NULL` |  |
| `expires_at` | timestamp | YES | `NULL` | indexed |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `expires_at` · `tokenable_type,tokenable_id` · `token` *(unique)*

### `plate_assignments`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `vehicle_id` | bigint(20) unsigned | NO |  | indexed |
| `plate_key` | varchar(40) | NO |  | indexed |
| `plate_code` | varchar(8) | YES | `NULL` | indexed |
| `plate_raw` | varchar(64) | YES | `NULL` |  |
| `from_date` | date | YES | `NULL` |  |
| `to_date` | date | YES | `NULL` |  |
| `is_current` | tinyint(1) | NO | `0` |  |
| `source` | varchar(20) | NO | `'backfill'` |  |
| `confidence` | varchar(12) | NO | `'high'` |  |
| `note` | text | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `vehicle_id` → `vehicles.id`

**Indexes:** `plate_code` · `plate_key` · `plate_key,is_current` · `vehicle_id,plate_key` *(unique)*

### `plate_codes`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `em_no` | int(10) unsigned | NO |  | unique |
| `letter_en` | varchar(32) | YES | `NULL` |  |
| `letter_ar` | varchar(32) | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `em_no` *(unique)*

### `policy_override_audits`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `user_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `user_name` | varchar(255) | YES | `NULL` |  |
| `vehicle_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `plate` | varchar(255) | YES | `NULL` |  |
| `action` | varchar(255) | NO | `'maintenance_over_active_rental'` | indexed |
| `reason_code` | varchar(255) | NO |  |  |
| `reason_label` | varchar(255) | YES | `NULL` |  |
| `notes` | text | YES | `NULL` |  |
| `rental_contract_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `rental_contract_no` | varchar(255) | YES | `NULL` |  |
| `result_contract_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `result_contract_no` | varchar(255) | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `result_contract_id` → `contracts.id` · `rental_contract_id` → `contracts.id` · `vehicle_id` → `vehicles.id` · `user_id` → `users.id`

**Indexes:** `action,created_at` · `rental_contract_id` · `result_contract_id` · `user_id` · `vehicle_id`

### `recommendations`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `capability_id` | varchar(60) | NO |  | indexed |
| `card_id` | varchar(60) | NO |  |  |
| `capability_version` | varchar(20) | NO | `'v1'` |  |
| `engine_version` | varchar(20) | NO | `'v1'` |  |
| `query_layer_version` | varchar(20) | YES | `NULL` |  |
| `evidence_schema_version` | varchar(20) | YES | `NULL` |  |
| `classifier_version` | varchar(20) | YES | `NULL` |  |
| `subject_type` | varchar(60) | YES | `NULL` | indexed |
| `subject_id` | bigint(20) unsigned | YES | `NULL` |  |
| `vehicle_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `workflow_state` | varchar(40) | YES | `NULL` | indexed |
| `tier` | tinyint(3) unsigned | NO |  |  |
| `confidence` | varchar(12) | NO |  |  |
| `strength` | varchar(12) | NO |  |  |
| `observation` | text | NO |  |  |
| `recommendation` | text | NO |  |  |
| `reasoning` | text | NO |  |  |
| `evidence` | longtext | NO |  |  |
| `actions` | longtext | YES | `NULL` |  |
| `presented_to` | bigint(20) unsigned | YES | `NULL` | indexed |
| `presented_at` | timestamp | YES | `NULL` |  |
| `expires_at` | timestamp | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `presented_to` → `users.id`

**Indexes:** `capability_id` · `presented_to` · `workflow_state` · `capability_id,created_at` · `subject_type,subject_id` · `vehicle_id,created_at`

### `recommendation_events`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `recommendation_id` | bigint(20) unsigned | NO |  | indexed |
| `event` | varchar(24) | NO |  | indexed |
| `actor_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `reason` | text | YES | `NULL` |  |
| `outcome_type` | varchar(60) | YES | `NULL` |  |
| `outcome_id` | bigint(20) unsigned | YES | `NULL` |  |
| `outcome_result` | varchar(24) | YES | `NULL` |  |
| `payload` | longtext | YES | `NULL` |  |
| `occurred_at` | timestamp | NO | `current_timestamp()` | indexed |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `recommendation_id` → `recommendations.id` · `actor_id` → `users.id`

**Indexes:** `actor_id` · `event` · `occurred_at` · `recommendation_id,event`

### `recurring_fault_reviews`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `status` | varchar(20) | NO | `'open'` | indexed |
| `decision` | varchar(30) | YES | `NULL` |  |
| `vehicle_id` | bigint(20) unsigned | NO |  | indexed |
| `maintenance_id` | bigint(20) unsigned | NO |  | indexed |
| `maintenance_task_id` | bigint(20) unsigned | NO |  | indexed |
| `previous_maintenance_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `previous_task_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `repair_inspection_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `symptom` | varchar(255) | NO |  |  |
| `category_key` | varchar(60) | YES | `NULL` |  |
| `previous_garage_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `previous_garage_name` | varchar(255) | YES | `NULL` |  |
| `previous_result` | varchar(20) | YES | `NULL` |  |
| `previous_repaired_at` | timestamp | YES | `NULL` |  |
| `days_since_repair` | int(10) unsigned | YES | `NULL` |  |
| `previous_odometer` | int(10) unsigned | YES | `NULL` |  |
| `current_odometer` | int(10) unsigned | YES | `NULL` |  |
| `distance_since_repair` | int(11) | YES | `NULL` |  |
| `occurrence_count` | int(10) unsigned | NO | `1` |  |
| `parts` | longtext | YES | `NULL` |  |
| `context` | longtext | YES | `NULL` |  |
| `opened_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `opened_by_name` | varchar(255) | YES | `NULL` |  |
| `opened_at` | timestamp | YES | `NULL` |  |
| `decided_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `decided_by_name` | varchar(255) | YES | `NULL` |  |
| `decided_at` | timestamp | YES | `NULL` |  |
| `decision_note` | text | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `maintenance_id` → `maintenances.id` · `previous_maintenance_id` → `maintenances.id` · `decided_by` → `users.id` · `previous_garage_id` → `vendors.id` · `vehicle_id` → `vehicles.id` · `opened_by` → `users.id` · `repair_inspection_id` → `repair_inspections.id` · `maintenance_task_id` → `maintenance_tasks.id` · `previous_task_id` → `maintenance_tasks.id`

**Indexes:** `decided_by` · `maintenance_id` · `maintenance_task_id` · `opened_by` · `previous_garage_id` · `previous_maintenance_id` · `previous_task_id` · `repair_inspection_id` · `status,decision` · `status` · `vehicle_id,status`

### `repair_inspections`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `maintenance_id` | bigint(20) unsigned | NO |  | indexed |
| `vehicle_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `fault_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `new_fault_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `inspector_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `result` | varchar(20) | NO |  | indexed |
| `failure_reason` | varchar(30) | YES | `NULL` |  |
| `notes` | text | YES | `NULL` |  |
| `previous_vendor_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `previous_repaired_at` | timestamp | YES | `NULL` |  |
| `days_since_repair` | int(10) unsigned | YES | `NULL` |  |
| `is_recurrence` | tinyint(1) | NO | `0` |  |
| `inspection_date` | timestamp | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `fault_id` → `maintenance_tasks.id` · `previous_vendor_id` → `vendors.id` · `new_fault_id` → `maintenance_tasks.id` · `maintenance_id` → `maintenances.id` · `inspector_id` → `users.id` · `vehicle_id` → `vehicles.id`

**Indexes:** `fault_id` · `inspector_id` · `maintenance_id,result` · `new_fault_id` · `previous_vendor_id` · `result` · `vehicle_id,result`

### `repair_visits`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `vehicle_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `vendor_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `started_at` | date | NO |  | indexed |
| `ended_at` | date | YES | `NULL` |  |
| `duration_days` | smallint(6) | YES | `NULL` |  |
| `is_open` | tinyint(1) | NO | `1` | indexed |
| `is_cancelled` | tinyint(1) | NO | `0` |  |
| `has_close_date` | tinyint(1) | NO | `0` |  |
| `event_row_count` | smallint(5) unsigned | NO | `1` |  |
| `maintenance_ids` | longtext | NO |  |  |
| `primary_maintenance_id` | bigint(20) unsigned | NO |  | unique |
| `origin_mix` | varchar(60) | NO | `''` |  |
| `multi_vendor_day` | tinyint(1) | NO | `0` |  |
| `grouping_window_days` | tinyint(3) unsigned | NO | `0` |  |
| `signature_set` | longtext | YES | `NULL` |  |
| `built_at` | timestamp | YES | `NULL` |  |

**Indexes:** `is_open` · `primary_maintenance_id` *(unique)* · `started_at` · `vehicle_id,started_at` · `vendor_id,started_at`

### `resolved_transfer_flags`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `maintenance_id` | bigint(20) unsigned | NO |  | indexed |
| `vehicle_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `from_vendor_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `from_garage` | varchar(191) | YES | `NULL` |  |
| `to_vendor_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `to_garage` | varchar(191) | YES | `NULL` |  |
| `note` | text | NO |  |  |
| `odometer` | int(10) unsigned | YES | `NULL` |  |
| `flagged_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `flagged_by` → `users.id` · `vehicle_id` → `vehicles.id` · `to_vendor_id` → `vendors.id` · `maintenance_id` → `maintenances.id` · `from_vendor_id` → `vendors.id`

**Indexes:** `flagged_by` · `from_vendor_id` · `maintenance_id` · `to_vendor_id` · `vehicle_id`

### `review_reminders`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `maintenance_id` | bigint(20) unsigned | NO |  | indexed |
| `vehicle_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `user_id` | bigint(20) unsigned | NO |  | indexed |
| `kind` | varchar(24) | NO | `'pending_review'` |  |
| `remind_at` | datetime | NO |  |  |
| `note` | text | YES | `NULL` |  |
| `status` | varchar(16) | NO | `'pending'` | indexed |
| `sent_at` | datetime | YES | `NULL` |  |
| `cancelled_at` | datetime | YES | `NULL` |  |
| `cancelled_reason` | varchar(32) | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `maintenance_id` · `user_id` · `vehicle_id` · `status,remind_at` · `maintenance_id,user_id,status`

### `rfq_lines`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `part_rfq_id` | bigint(20) unsigned | NO |  | indexed |
| `part_request_id` | bigint(20) unsigned | NO |  | indexed |
| `quantity` | decimal(10,2) | NO | `1.00` |  |
| `awarded_quote_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `awarded_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `awarded_by_name` | varchar(255) | YES | `NULL` |  |
| `awarded_at` | timestamp | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `part_rfq_id` → `part_rfqs.id` · `part_request_id` → `part_requests.id` · `awarded_by` → `users.id`

**Indexes:** `awarded_by` · `awarded_quote_id` · `part_request_id` · `part_rfq_id`

### `roles`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `name` | varchar(255) | NO |  | indexed |
| `guard_name` | varchar(255) | NO |  |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `name,guard_name` *(unique)*

### `role_has_permissions`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `permission_id` | bigint(20) unsigned | NO |  | PK |
| `role_id` | bigint(20) unsigned | NO |  | PK |

**Foreign keys:** `role_id` → `roles.id` · `permission_id` → `permissions.id`

**Indexes:** `role_id`

### `service_catalog`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `slug` | varchar(80) | NO |  | unique |
| `name` | varchar(120) | NO |  |  |
| `name_ar` | varchar(120) | YES | `NULL` |  |
| `category_key` | varchar(40) | NO |  | indexed |
| `interval_km` | int(10) unsigned | YES | `NULL` |  |
| `interval_months` | smallint(5) unsigned | YES | `NULL` |  |
| `service_reminder_type` | varchar(40) | YES | `NULL` |  |
| `component_slug` | varchar(120) | YES | `NULL` |  |
| `default_labor_hours` | decimal(6,2) | YES | `NULL` |  |
| `is_active` | tinyint(1) | NO | `1` |  |
| `sort_order` | smallint(5) unsigned | NO | `0` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `category_key` · `slug` *(unique)*

### `service_due_snoozes`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `vehicle_id` | bigint(20) unsigned | NO |  | indexed |
| `service_type` | varchar(80) | YES | `NULL` |  |
| `snoozed_until` | date | YES | `NULL` |  |
| `reason` | varchar(500) | YES | `NULL` |  |
| `active` | tinyint(1) | NO | `1` |  |
| `snoozed_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `snoozed_by_name` | varchar(120) | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `vehicle_id` → `vehicles.id` · `snoozed_by` → `users.id`

**Indexes:** `snoozed_by` · `vehicle_id,active`

### `service_records`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `vehicle_id` | bigint(20) unsigned | NO |  | indexed |
| `maintenance_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `maintenance_task_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `service_type` | varchar(40) | NO |  |  |
| `description` | varchar(255) | NO |  |  |
| `performed_at` | date | NO |  |  |
| `odometer` | int(10) unsigned | YES | `NULL` |  |
| `workshop_vendor_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `technician_name` | varchar(120) | YES | `NULL` |  |
| `labor_cost` | decimal(12,2) | YES | `NULL` |  |
| `materials_cost` | decimal(12,2) | YES | `NULL` |  |
| `duration_hours` | decimal(6,2) | YES | `NULL` |  |
| `result` | varchar(20) | NO |  |  |
| `related_component_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `source` | varchar(20) | NO |  | indexed |
| `source_line_item_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `source_invoice_item_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `performed_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `performed_by_name` | varchar(120) | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `maintenance_id` → `maintenances.id` · `source_invoice_item_id` → `invoice_items.id` · `related_component_id` → `vehicle_components.id` · `workshop_vendor_id` → `vendors.id` · `performed_by` → `users.id` · `vehicle_id` → `vehicles.id` · `maintenance_task_id` → `maintenance_tasks.id` · `source_line_item_id` → `maintenance_line_items.id`

**Indexes:** `maintenance_id` · `maintenance_task_id` · `performed_by` · `related_component_id` · `source` · `source_invoice_item_id` · `source_line_item_id` · `vehicle_id,performed_at` · `workshop_vendor_id` · `vehicle_id,service_type,performed_at`

### `service_reminders`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `vehicle_id` | bigint(20) unsigned | NO |  | indexed |
| `service_type` | varchar(40) | NO |  |  |
| `name` | varchar(255) | YES | `NULL` |  |
| `interval_km` | int(10) unsigned | YES | `NULL` |  |
| `interval_days` | int(10) unsigned | YES | `NULL` |  |
| `last_service_odometer` | int(10) unsigned | YES | `NULL` |  |
| `last_service_at` | date | YES | `NULL` |  |
| `next_due_odometer` | int(10) unsigned | YES | `NULL` |  |
| `next_due_at` | date | YES | `NULL` | indexed |
| `source` | varchar(10) | NO | `'manual'` |  |
| `is_muted` | tinyint(1) | NO | `0` |  |
| `active` | tinyint(1) | NO | `1` | indexed |
| `last_notified_at` | timestamp | YES | `NULL` |  |
| `notified_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `notified_count` | int(10) unsigned | NO | `0` |  |
| `notes` | text | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `vehicle_id` → `vehicles.id` · `notified_by` → `users.id`

**Indexes:** `active` · `next_due_at` · `notified_by` · `vehicle_id,service_type` *(unique)*

### `sessions`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | varchar(255) | NO |  | PK |
| `user_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `ip_address` | varchar(45) | YES | `NULL` |  |
| `user_agent` | text | YES | `NULL` |  |
| `payload` | longtext | NO |  |  |
| `last_activity` | int(11) | NO |  | indexed |

**Indexes:** `last_activity` · `user_id`

### `simulation_events`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `scenario` | varchar(40) | NO |  | indexed |
| `vehicle_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `maintenance_id` | bigint(20) unsigned | YES | `NULL` |  |
| `snapshot` | longtext | YES | `NULL` |  |
| `label` | varchar(255) | YES | `NULL` |  |
| `created_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `vehicle_id` → `vehicles.id` · `created_by` → `users.id`

**Indexes:** `created_by` · `scenario` · `vehicle_id`

### `supplier_payments`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `vendor_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `payee_name` | varchar(255) | YES | `NULL` |  |
| `payment_date` | date | NO |  |  |
| `amount` | decimal(14,2) | NO |  |  |
| `currency` | varchar(3) | NO | `'AED'` |  |
| `method` | varchar(20) | NO |  |  |
| `reference` | varchar(120) | YES | `NULL` |  |
| `notes` | text | YES | `NULL` |  |
| `photo_disk` | varchar(20) | YES | `NULL` |  |
| `photo_key` | varchar(255) | YES | `NULL` |  |
| `status` | varchar(12) | NO | `'recorded'` | indexed |
| `cancelled_at` | timestamp | YES | `NULL` |  |
| `cancellation_reason` | text | YES | `NULL` |  |
| `recorded_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `recorded_by_name` | varchar(255) | YES | `NULL` |  |
| `recorded_at` | timestamp | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `recorded_by` → `users.id` · `vendor_id` → `vendors.id`

**Indexes:** `recorded_by` · `status,payment_date` · `vendor_id,payment_date`

### `supplier_quotes`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `rfq_line_id` | bigint(20) unsigned | NO |  | indexed |
| `vendor_id` | bigint(20) unsigned | NO |  | indexed |
| `unit_price` | decimal(12,2) | NO |  |  |
| `quantity` | decimal(10,2) | NO | `1.00` |  |
| `currency` | varchar(3) | NO | `'AED'` |  |
| `lead_time_days` | smallint(5) unsigned | YES | `NULL` |  |
| `expected_delivery_date` | date | YES | `NULL` |  |
| `status` | varchar(10) | NO | `'pending'` | indexed |
| `notes` | text | YES | `NULL` |  |
| `submitted_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `submitted_by_name` | varchar(255) | YES | `NULL` |  |
| `submitted_at` | timestamp | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `vendor_id` → `vendors.id` · `submitted_by` → `users.id` · `rfq_line_id` → `rfq_lines.id`

**Indexes:** `rfq_line_id,vendor_id` · `status` · `submitted_by` · `vendor_id`

### `sync_changes`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `sync_run_id` | bigint(20) unsigned | NO |  | indexed |
| `contract_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `contract_no` | varchar(255) | YES | `NULL` |  |
| `external_id` | varchar(255) | YES | `NULL` |  |
| `operation` | varchar(16) | NO |  |  |
| `snapshot` | longtext | YES | `NULL` |  |
| `changes` | longtext | YES | `NULL` |  |
| `changed_count` | int(10) unsigned | NO | `0` |  |
| `created_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `sync_run_id` → `sync_runs.id` · `contract_id` → `contracts.id`

**Indexes:** `contract_id` · `sync_run_id,operation`

### `sync_corrections`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `sync_run_id` | bigint(20) unsigned | NO |  | indexed |
| `contract_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `contract_no` | varchar(255) | YES | `NULL` |  |
| `external_id` | varchar(255) | YES | `NULL` |  |
| `field` | varchar(255) | NO |  |  |
| `old_value` | text | YES | `NULL` |  |
| `action` | varchar(255) | NO | `'cleared'` |  |
| `created_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `sync_run_id` → `sync_runs.id` · `contract_id` → `contracts.id`

**Indexes:** `contract_id` · `sync_run_id,field`

### `sync_runs`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `action` | varchar(255) | NO |  |  |
| `phase` | varchar(255) | YES | `NULL` |  |
| `status` | varchar(255) | NO | `'running'` |  |
| `total` | int(10) unsigned | NO | `0` |  |
| `processed` | int(10) unsigned | NO | `0` |  |
| `result` | longtext | YES | `NULL` |  |
| `error` | text | YES | `NULL` |  |
| `started_at` | timestamp | YES | `NULL` |  |
| `finished_at` | timestamp | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

### `traceability_snapshots`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `taken_on` | date | NO |  | unique |
| `taken_at` | timestamp | NO | `current_timestamp()` |  |
| `tickets_total` | int(10) unsigned | NO | `0` |  |
| `tickets_verified` | int(10) unsigned | NO | `0` |  |
| `tickets_legacy` | int(10) unsigned | NO | `0` |  |
| `total_cost` | decimal(14,2) | NO | `0.00` |  |
| `verified_cost` | decimal(14,2) | NO | `0.00` |  |
| `legacy_cost` | decimal(14,2) | NO | `0.00` |  |
| `unverified_cost` | decimal(14,2) | NO | `0.00` |  |
| `coverage_pct` | decimal(5,2) | NO | `0.00` |  |
| `by_source` | longtext | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `taken_on` *(unique)*

### `users`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `name` | varchar(255) | NO |  |  |
| `email` | varchar(255) | NO |  | unique |
| `email_verified_at` | timestamp | YES | `NULL` |  |
| `password` | varchar(255) | NO |  |  |
| `status` | enum('active','suspended') | NO | `'active'` |  |
| `last_login_at` | timestamp | YES | `NULL` |  |
| `last_logout_at` | timestamp | YES | `NULL` |  |
| `last_seen_at` | timestamp | YES | `NULL` |  |
| `last_page` | varchar(255) | YES | `NULL` |  |
| `last_path` | varchar(255) | YES | `NULL` |  |
| `last_ip` | varchar(45) | YES | `NULL` |  |
| `last_user_agent` | text | YES | `NULL` |  |
| `failed_login_count` | int(10) unsigned | NO | `0` |  |
| `last_failed_login_at` | timestamp | YES | `NULL` |  |
| `last_password_change_at` | timestamp | YES | `NULL` |  |
| `last_role_change_at` | timestamp | YES | `NULL` |  |
| `created_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `remember_token` | varchar(100) | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `created_by` → `users.id`

**Indexes:** `created_by` · `email` *(unique)*

### `user_activity_events`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `user_id` | bigint(20) unsigned | NO |  | indexed |
| `type` | varchar(16) | NO |  |  |
| `method` | varchar(8) | YES | `NULL` |  |
| `page` | varchar(255) | YES | `NULL` |  |
| `entity` | varchar(80) | YES | `NULL` |  |
| `entity_id` | varchar(64) | YES | `NULL` |  |
| `description` | varchar(255) | YES | `NULL` |  |
| `status_code` | smallint(5) unsigned | YES | `NULL` |  |
| `path` | varchar(255) | YES | `NULL` |  |
| `ip` | varchar(45) | YES | `NULL` |  |
| `user_agent` | text | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` | indexed |

**Foreign keys:** `user_id` → `users.id`

**Indexes:** `user_id,type,created_at` · `created_at` · `user_id,created_at`

### `vehicles`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `code` | varchar(255) | YES | `NULL` | unique |
| `car_serial` | bigint(20) unsigned | YES | `NULL` | indexed |
| `vin` | varchar(32) | YES | `NULL` | unique |
| `engine_no` | varchar(64) | YES | `NULL` |  |
| `driver_no` | varchar(32) | YES | `NULL` |  |
| `keys_number` | tinyint(3) unsigned | YES | `NULL` |  |
| `auto_gear` | tinyint(1) | YES | `NULL` |  |
| `cylinders` | tinyint(3) unsigned | YES | `NULL` |  |
| `horse_power` | smallint(5) unsigned | YES | `NULL` |  |
| `doors` | tinyint(3) unsigned | YES | `NULL` |  |
| `seats` | tinyint(3) unsigned | YES | `NULL` |  |
| `passengers` | tinyint(3) unsigned | YES | `NULL` |  |
| `wheel_drive` | tinyint(3) unsigned | YES | `NULL` |  |
| `location` | varchar(64) | YES | `NULL` |  |
| `salik_tag_no` | varchar(64) | YES | `NULL` |  |
| `plate_no` | varchar(255) | YES | `NULL` | indexed |
| `plate_key` | varchar(40) | YES | `NULL` | indexed |
| `plate_code` | varchar(8) | YES | `NULL` | indexed |
| `make` | varchar(255) | YES | `NULL` |  |
| `model` | varchar(255) | YES | `NULL` |  |
| `year` | smallint(5) unsigned | YES | `NULL` |  |
| `color` | varchar(255) | YES | `NULL` |  |
| `category` | varchar(255) | YES | `NULL` |  |
| `sheet_category` | varchar(255) | YES | `NULL` |  |
| `vehicle_class` | varchar(40) | YES | `NULL` | indexed |
| `status` | varchar(32) | NO | `'ready'` |  |
| `status_no` | tinyint(3) unsigned | YES | `NULL` |  |
| `for_sale` | tinyint(1) | NO | `0` |  |
| `operational_status` | enum('available','rented','maintenance','test','transfer','sale_prep','in_transit') | NO | `'available'` |  |
| `transit_destination` | varchar(255) | YES | `NULL` |  |
| `condition_grade` | varchar(10) | NO | `'green'` |  |
| `condition_note` | text | YES | `NULL` |  |
| `condition_graded_at` | timestamp | YES | `NULL` |  |
| `condition_graded_by` | varchar(255) | YES | `NULL` |  |
| `is_deferred_maintenance` | tinyint(1) | NO | `0` |  |
| `deferred_maintenance_reason` | text | YES | `NULL` |  |
| `deferred_maintenance_flagged_at` | timestamp | YES | `NULL` |  |
| `deferred_maintenance_flagged_by` | varchar(255) | YES | `NULL` |  |
| `cleaning_status` | varchar(20) | YES | `NULL` |  |
| `gps_last_seen_at` | timestamp | YES | `NULL` |  |
| `odometer` | int(10) unsigned | NO | `0` |  |
| `odometer_source` | varchar(32) | YES | `NULL` |  |
| `odometer_source_at` | datetime | YES | `NULL` |  |
| `odometer_reading_on` | date | YES | `NULL` |  |
| `engine_hours` | decimal(10,1) | YES | `NULL` |  |
| `source` | enum('new','used','auction','accident') | YES | `NULL` |  |
| `purchase_price` | decimal(12,2) | YES | `NULL` |  |
| `purchase_date` | date | YES | `NULL` |  |
| `warranty_end_date` | date | YES | `NULL` |  |
| `warranty_end_km` | int(10) unsigned | YES | `NULL` |  |
| `service_due_date` | date | YES | `NULL` |  |
| `service_due_km` | int(10) unsigned | YES | `NULL` |  |
| `battery_last_changed` | date | YES | `NULL` |  |
| `last_service_odometer` | int(10) unsigned | YES | `NULL` |  |
| `service_interval_km` | int(10) unsigned | YES | `NULL` |  |
| `service_synced_at` | timestamp | YES | `NULL` |  |
| `baseline_odometer` | int(10) unsigned | YES | `NULL` |  |
| `baseline_synced_at` | timestamp | YES | `NULL` |  |
| `hour_rent_value` | decimal(12,2) | YES | `NULL` |  |
| `day_rent_value` | decimal(12,2) | YES | `NULL` |  |
| `week_rent_value` | decimal(12,2) | YES | `NULL` |  |
| `month_rent_value` | decimal(12,2) | YES | `NULL` |  |
| `year_rent_value` | decimal(12,2) | YES | `NULL` |  |
| `miles_allowed_pd` | int(10) unsigned | YES | `NULL` |  |
| `miles_allowed_pm` | int(10) unsigned | YES | `NULL` |  |
| `extra_mile_charge` | decimal(10,2) | YES | `NULL` |  |
| `full_fuel_cost` | decimal(10,2) | YES | `NULL` |  |
| `replacement_due_date` | date | YES | `NULL` |  |
| `notes` | text | YES | `NULL` |  |
| `external_id` | varchar(255) | YES | `NULL` | indexed |
| `synced_at` | timestamp | YES | `NULL` |  |
| `origin` | enum('web','sheet','api') | NO | `'web'` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |
| `deleted_at` | timestamp | YES | `NULL` |  |

**Indexes:** `car_serial` · `code` *(unique)* · `external_id` · `plate_code` · `plate_key` · `plate_no` · `vehicle_class` · `vin` *(unique)*

### `vehicle_components`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `component_catalog_id` | bigint(20) unsigned | NO |  | indexed |
| `vehicle_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `serial_no` | varchar(80) | YES | `NULL` | indexed |
| `part_number` | varchar(80) | YES | `NULL` | indexed |
| `brand` | varchar(80) | YES | `NULL` | indexed |
| `model` | varchar(120) | YES | `NULL` |  |
| `label` | varchar(160) | YES | `NULL` |  |
| `quantity` | decimal(8,2) | NO | `1.00` |  |
| `position` | varchar(20) | YES | `NULL` |  |
| `status` | varchar(20) | NO |  | indexed |
| `location` | varchar(30) | NO |  |  |
| `installed_at` | datetime | YES | `NULL` |  |
| `installed_odometer` | int(10) unsigned | YES | `NULL` |  |
| `installed_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `installed_by_name` | varchar(120) | YES | `NULL` |  |
| `technician_name` | varchar(120) | YES | `NULL` |  |
| `installer_vendor_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `supplier_vendor_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `purchase_cost` | decimal(12,2) | YES | `NULL` |  |
| `currency` | varchar(3) | NO | `'AED'` |  |
| `warranty_months` | smallint(5) unsigned | YES | `NULL` |  |
| `warranty_until` | date | YES | `NULL` | indexed |
| `source_part_purchase_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `source_line_item_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `source_maintenance_task_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `source` | varchar(20) | NO |  |  |
| `evidence_channel` | varchar(24) | YES | `NULL` | indexed |
| `acquisition` | varchar(24) | YES | `NULL` |  |
| `write_mode` | varchar(12) | YES | `NULL` |  |
| `validation_status` | varchar(12) | NO | `'provisional'` | indexed |
| `validated_at` | datetime | YES | `NULL` |  |
| `validated_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `removed_at` | datetime | YES | `NULL` | indexed |
| `removed_odometer` | int(10) unsigned | YES | `NULL` |  |
| `removed_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `removed_by_name` | varchar(120) | YES | `NULL` |  |
| `removal_reason` | varchar(30) | YES | `NULL` | indexed |
| `removal_note` | text | YES | `NULL` |  |
| `disposition` | varchar(30) | YES | `NULL` | indexed |
| `removal_maintenance_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |
| `replaced_by_component_id` | bigint(20) unsigned | YES | `NULL` | indexed |

**Foreign keys:** `source_part_purchase_id` → `part_purchases.id` · `removal_maintenance_id` → `maintenances.id` · `source_maintenance_task_id` → `maintenance_tasks.id` · `installer_vendor_id` → `vendors.id` · `vehicle_id` → `vehicles.id` · `source_line_item_id` → `maintenance_line_items.id` · `installed_by` → `users.id` · `validated_by` → `users.id` · `replaced_by_component_id` → `vehicle_components.id` · `component_catalog_id` → `component_catalog.id` · `supplier_vendor_id` → `vendors.id` · `removed_by` → `users.id`

**Indexes:** `component_catalog_id,removed_at` · `evidence_channel` · `removed_at` · `component_catalog_id,vehicle_id,position,status` · `status,warranty_until` · `source_maintenance_task_id,component_catalog_id` · `brand` · `disposition` · `installed_by` · `installer_vendor_id` · `part_number` · `removal_maintenance_id` · `removal_reason` · `removed_by` · `replaced_by_component_id` · `serial_no` · `source_line_item_id` · `source_part_purchase_id` · `status,location` · `supplier_vendor_id` · `validated_by` · `validation_status` · `vehicle_id,status` · `warranty_until`

### `vehicle_expenses`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `car_serial` | bigint(20) unsigned | NO |  | indexed |
| `vehicle_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `entry_date` | date | YES | `NULL` | indexed |
| `account_type` | varchar(255) | YES | `NULL` |  |
| `remarks` | text | YES | `NULL` |  |
| `category` | varchar(32) | NO | `'other'` |  |
| `category_matched` | varchar(64) | YES | `NULL` |  |
| `debit` | decimal(14,2) | NO | `0.00` |  |
| `credit` | decimal(14,2) | NO | `0.00` |  |
| `amount` | decimal(14,2) | NO | `0.00` |  |
| `source` | varchar(255) | NO | `'excel'` | indexed |
| `imported_at` | timestamp | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `car_serial` · `entry_date` · `source,car_serial` · `source,category` · `source` · `source,vehicle_id` · `vehicle_id`

### `vehicle_garage_locations`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `vehicle_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `car_label` | varchar(255) | NO |  |  |
| `plate_text` | varchar(32) | YES | `NULL` |  |
| `garage_name` | varchar(255) | NO |  |  |
| `vendor_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `sheet_row` | int(10) unsigned | NO |  |  |
| `imported_at` | datetime | NO |  |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `vendor_id` → `vendors.id` · `vehicle_id` → `vehicles.id`

**Indexes:** `vehicle_id` · `vendor_id`

### `vehicle_locations`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `slug` | varchar(60) | NO |  | unique |
| `name` | varchar(120) | NO |  |  |
| `name_ar` | varchar(120) | YES | `NULL` |  |
| `group_key` | varchar(40) | NO |  | indexed |
| `precision` | varchar(20) | NO | `'panel'` | indexed |
| `inspection_zone` | varchar(40) | YES | `NULL` | indexed |
| `area_key` | varchar(40) | YES | `NULL` | indexed |
| `aliases` | longtext | YES | `NULL` |  |
| `is_active` | tinyint(1) | NO | `1` |  |
| `edited_in_app` | tinyint(1) | NO | `0` |  |
| `sort_order` | smallint(5) unsigned | NO | `0` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `area_key` · `group_key` · `inspection_zone` · `precision` · `slug` *(unique)*

### `vehicle_location_groups`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `key` | varchar(40) | NO |  | unique |
| `label` | varchar(120) | NO |  |  |
| `label_ar` | varchar(120) | YES | `NULL` |  |
| `is_active` | tinyint(1) | NO | `1` |  |
| `sort_order` | smallint(5) unsigned | NO | `0` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Indexes:** `key` *(unique)*

### `vehicle_log_events`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `vehicle_id` | bigint(20) unsigned | NO |  | indexed |
| `maintenance_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `maintenance_ref` | bigint(20) unsigned | YES | `NULL` | indexed |
| `maintenance_task_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `linked_contract_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `event_type` | varchar(40) | NO |  | indexed |
| `source_tag` | varchar(20) | NO |  | indexed |
| `workflow_status` | varchar(30) | YES | `NULL` |  |
| `description` | varchar(255) | YES | `NULL` |  |
| `meta` | longtext | YES | `NULL` |  |
| `actor_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `occurred_at` | timestamp | NO | `current_timestamp()` | indexed |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `linked_contract_id` → `contracts.id` · `actor_id` → `users.id` · `vehicle_id` → `vehicles.id` · `maintenance_task_id` → `maintenance_tasks.id` · `maintenance_id` → `maintenances.id`

**Indexes:** `actor_id` · `event_type` · `linked_contract_id` · `maintenance_id` · `maintenance_task_id,occurred_at` · `occurred_at` · `source_tag` · `vehicle_id,occurred_at` · `maintenance_ref`

### `vehicle_registrations`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `vehicle_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `chasis_no` | varchar(255) | YES | `NULL` | indexed |
| `expiry_date` | date | YES | `NULL` |  |
| `status` | varchar(255) | YES | `NULL` |  |
| `fines_count` | int(11) | YES | `NULL` |  |
| `fines_amount` | decimal(12,2) | YES | `NULL` |  |
| `mortgaged_by` | varchar(255) | YES | `NULL` |  |
| `is_mortgaged` | tinyint(1) | YES | `NULL` |  |
| `insurance_company_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `insurance_company_no` | varchar(32) | YES | `NULL` |  |
| `insurance_no` | varchar(64) | YES | `NULL` |  |
| `insurance_issue_date` | date | YES | `NULL` |  |
| `insurance_expiry` | date | YES | `NULL` |  |
| `insurance_type` | varchar(64) | YES | `NULL` |  |
| `insurance_bear_amount` | decimal(12,2) | YES | `NULL` |  |
| `external_id` | varchar(255) | YES | `NULL` | indexed |
| `synced_at` | timestamp | YES | `NULL` |  |
| `origin` | enum('web','sheet','api') | NO | `'web'` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |
| `deleted_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `vehicle_id` → `vehicles.id` · `insurance_company_id` → `vendors.id`

**Indexes:** `chasis_no` · `external_id` · `insurance_company_id` · `vehicle_id`

### `vendors`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `name` | varchar(255) | NO |  |  |
| `type` | enum('garage','fuel_station','parts_supplier','insurance','service_center','other') | NO | `'other'` |  |
| `phone` | varchar(255) | YES | `NULL` |  |
| `email` | varchar(255) | YES | `NULL` |  |
| `rating` | decimal(3,2) | YES | `NULL` |  |
| `default_lead_time_days` | smallint(5) unsigned | YES | `NULL` |  |
| `payment_terms_days` | smallint(5) unsigned | YES | `NULL` |  |
| `payment_terms_note` | varchar(191) | YES | `NULL` |  |
| `notes` | text | YES | `NULL` |  |
| `active` | tinyint(1) | NO | `1` |  |
| `external_id` | varchar(255) | YES | `NULL` | indexed |
| `synced_at` | timestamp | YES | `NULL` |  |
| `origin` | enum('web','sheet','api') | NO | `'web'` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |
| `deleted_at` | timestamp | YES | `NULL` |  |

**Indexes:** `external_id`

### `warranties`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `kind` | varchar(10) | NO |  | indexed |
| `vehicle_id` | bigint(20) unsigned | NO |  | indexed |
| `part_purchase_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `vehicle_component_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `maintenance_task_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `maintenance_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `subject` | varchar(300) | NO |  |  |
| `component_catalog_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `provider_vendor_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `provider_name` | varchar(255) | YES | `NULL` |  |
| `reference_no` | varchar(120) | YES | `NULL` |  |
| `starts_on` | date | NO |  |  |
| `start_odometer` | int(10) unsigned | YES | `NULL` |  |
| `duration_months` | smallint(5) unsigned | YES | `NULL` |  |
| `duration_km` | int(10) unsigned | YES | `NULL` |  |
| `expires_on` | date | YES | `NULL` | indexed |
| `expires_at_km` | int(10) unsigned | YES | `NULL` |  |
| `status` | varchar(10) | NO | `'active'` | indexed |
| `void_reason` | text | YES | `NULL` |  |
| `notes` | text | YES | `NULL` |  |
| `created_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `created_by_name` | varchar(255) | YES | `NULL` |  |
| `updated_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `updated_by_name` | varchar(255) | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |
| `deleted_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `component_catalog_id` → `component_catalog.id` · `part_purchase_id` → `part_purchases.id` · `vehicle_id` → `vehicles.id` · `maintenance_task_id` → `maintenance_tasks.id` · `vehicle_component_id` → `vehicle_components.id` · `maintenance_id` → `maintenances.id` · `updated_by` → `users.id` · `created_by` → `users.id` · `provider_vendor_id` → `vendors.id`

**Indexes:** `component_catalog_id` · `created_by` · `expires_on` · `kind` · `kind,status,expires_on` · `maintenance_id` · `maintenance_task_id,status` · `part_purchase_id` · `provider_vendor_id` · `status` · `updated_by` · `vehicle_component_id` · `vehicle_id,status,expires_on`

### `warranty_claims`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| `id` | bigint(20) unsigned | NO |  | PK, auto |
| `warranty_id` | bigint(20) unsigned | NO |  | indexed |
| `vehicle_id` | bigint(20) unsigned | NO |  | indexed |
| `maintenance_id` | bigint(20) unsigned | YES | `NULL` | indexed |
| `failure_description` | text | YES | `NULL` |  |
| `claimed_on` | date | NO |  |  |
| `claim_odometer` | int(10) unsigned | YES | `NULL` |  |
| `was_in_window` | tinyint(1) | YES | `NULL` |  |
| `window_evidence` | varchar(200) | YES | `NULL` |  |
| `outcome` | varchar(12) | NO | `'pending'` | indexed |
| `outcome_reason` | text | YES | `NULL` |  |
| `resolved_on` | date | YES | `NULL` |  |
| `recovered_amount` | decimal(12,2) | YES | `NULL` |  |
| `currency` | varchar(3) | NO | `'AED'` |  |
| `remedy` | varchar(12) | YES | `NULL` |  |
| `created_by` | bigint(20) unsigned | YES | `NULL` | indexed |
| `created_by_name` | varchar(255) | YES | `NULL` |  |
| `created_at` | timestamp | YES | `NULL` |  |
| `updated_at` | timestamp | YES | `NULL` |  |
| `deleted_at` | timestamp | YES | `NULL` |  |

**Foreign keys:** `warranty_id` → `warranties.id` · `vehicle_id` → `vehicles.id` · `maintenance_id` → `maintenances.id` · `created_by` → `users.id`

**Indexes:** `created_by` · `maintenance_id` · `outcome` · `vehicle_id,claimed_on` · `warranty_id,outcome`

