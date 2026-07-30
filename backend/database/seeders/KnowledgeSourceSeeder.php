<?php

namespace Database\Seeders;

use App\Models\KnowledgeSource;
use Illuminate\Database\Seeder;

/**
 * The knowledge-source registry — every body of automotive documentation the engine knows about,
 * and, crucially, HOW each one may lawfully be used.
 *
 * Read the `access` column before adding anything here:
 *
 *  - ACCESS_LICENSED   Paid, copyrighted products (ALLDATA, Mitchell 1, Haynes, Chilton, OEM
 *                      factory manuals). They are registered so the system KNOWS they exist and can
 *                      show them as available-once-licensed — but the retriever skips them until
 *                      credentials are configured in config('keyword_ai.licensed_sources'). It
 *                      never falls back to fetching their public website, which is exactly what
 *                      their terms forbid. Licensing one is a commercial decision, not a code change.
 *
 *  - ACCESS_PUBLIC_WEB Genuinely public technical documentation — government safety and recall
 *                      databases, supplier technical libraries, standards-body abstracts. These
 *                      carry a `domains` allowlist that is passed straight to the web-search tool,
 *                      so the model can only ever read these hosts.
 *
 *  - ACCESS_DERIVED    Our own maintenance history. Not a document at all, but it is evidence, and
 *                      it deserves a row so a fleet-derived claim can cite something.
 *
 * Seeded with firstOrCreate so tuning a trust weight or adding a domain by hand survives a re-seed.
 */
class KnowledgeSourceSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->sources() as $source) {
            KnowledgeSource::firstOrCreate(
                ['key' => $source['key']],
                $source
            );
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function sources(): array
    {
        return [
            // ---- Tier 1: manufacturer / OEM -------------------------------------------------
            [
                'key' => 'oem_service_manuals',
                'name' => 'OEM Factory Service Manuals',
                'publisher' => 'Vehicle manufacturers',
                'tier' => KnowledgeSource::TIER_OEM,
                'trust_weight' => 100,
                'access' => KnowledgeSource::ACCESS_LICENSED,
                'notes' => 'The authoritative procedure for a specific vehicle. Per-manufacturer '
                    .'subscription or dealer portal access; ingest as documents once licensed.',
            ],
            [
                'key' => 'manufacturer_tsb',
                'name' => 'Manufacturer Technical Service Bulletins',
                'publisher' => 'Vehicle manufacturers',
                'tier' => KnowledgeSource::TIER_OEM,
                'trust_weight' => 95,
                'access' => KnowledgeSource::ACCESS_PUBLIC_WEB,
                // Manufacturers publish a subset of TSBs and recall notices publicly.
                'domains' => ['nhtsa.gov', 'static.nhtsa.gov', 'toyota.com', 'techinfo.toyota.com',
                              'bmwtechinfo.com', 'mbusa.com', 'fordservicecontent.com', 'nissan-techinfo.com'],
                'notes' => 'Publicly published bulletins and recall notices only.',
            ],

            // ---- Tier 2: professional bodies and suppliers -----------------------------------
            [
                'key' => 'ase',
                'name' => 'ASE — National Institute for Automotive Service Excellence',
                'publisher' => 'ASE',
                'tier' => KnowledgeSource::TIER_PROFESSIONAL,
                'trust_weight' => 90,
                'access' => KnowledgeSource::ACCESS_PUBLIC_WEB,
                'domains' => ['ase.com', 'asecert.org'],
                'notes' => 'Certification study material and task lists — strong for standard '
                    .'terminology and inspection procedure naming.',
            ],
            [
                'key' => 'bosch',
                'name' => 'Bosch Automotive Technical Documentation',
                'publisher' => 'Robert Bosch GmbH',
                'tier' => KnowledgeSource::TIER_PROFESSIONAL,
                'trust_weight' => 92,
                'access' => KnowledgeSource::ACCESS_PUBLIC_WEB,
                'domains' => ['bosch-automotive-aftermarket.com', 'boschaftermarket.com', 'bosch.com'],
            ],
            [
                'key' => 'denso',
                'name' => 'Denso Technical Documentation',
                'publisher' => 'Denso Corporation',
                'tier' => KnowledgeSource::TIER_PROFESSIONAL,
                'trust_weight' => 88,
                'access' => KnowledgeSource::ACCESS_PUBLIC_WEB,
                'domains' => ['denso-am.eu', 'densoautoparts.com', 'denso.com'],
            ],
            [
                'key' => 'ngk',
                'name' => 'NGK / NTK Technical Information',
                'publisher' => 'NGK Spark Plug Co.',
                'tier' => KnowledgeSource::TIER_PROFESSIONAL,
                'trust_weight' => 86,
                'access' => KnowledgeSource::ACCESS_PUBLIC_WEB,
                'domains' => ['ngkntk.com', 'ngksparkplugs.com', 'ngk.de'],
            ],
            [
                'key' => 'acdelco',
                'name' => 'ACDelco Technical Resources',
                'publisher' => 'ACDelco / General Motors',
                'tier' => KnowledgeSource::TIER_PROFESSIONAL,
                'trust_weight' => 84,
                'access' => KnowledgeSource::ACCESS_PUBLIC_WEB,
                'domains' => ['acdelco.com', 'acdelcotechconnect.com'],
            ],
            [
                'key' => 'sae',
                'name' => 'SAE International Technical Papers',
                'publisher' => 'SAE International',
                'tier' => KnowledgeSource::TIER_PROFESSIONAL,
                'trust_weight' => 90,
                'access' => KnowledgeSource::ACCESS_PUBLIC_WEB,
                'domains' => ['sae.org', 'saemobilus.sae.org'],
                'notes' => 'Abstracts and standards metadata are public; full papers are paywalled '
                    .'— ingest full text only under an institutional subscription.',
            ],

            // ---- Tier 3: professional repair databases (all licensed) ------------------------
            [
                'key' => 'alldata',
                'name' => 'ALLDATA Repair',
                'publisher' => 'ALLDATA LLC',
                'tier' => KnowledgeSource::TIER_PROFESSIONAL,
                'trust_weight' => 94,
                'access' => KnowledgeSource::ACCESS_LICENSED,
                'notes' => 'Subscription repair database. Requires an ALLDATA licence + API '
                    .'credentials; content may not be scraped or redistributed.',
            ],
            [
                'key' => 'mitchell1',
                'name' => 'Mitchell 1 ProDemand',
                'publisher' => 'Mitchell 1',
                'tier' => KnowledgeSource::TIER_PROFESSIONAL,
                'trust_weight' => 94,
                'access' => KnowledgeSource::ACCESS_LICENSED,
                'notes' => 'Subscription repair database — real-world fix data and OEM procedures. '
                    .'Licence required.',
            ],
            [
                'key' => 'motor',
                'name' => 'MOTOR Information Systems',
                'publisher' => 'MOTOR (Hearst)',
                'tier' => KnowledgeSource::TIER_PROFESSIONAL,
                'trust_weight' => 88,
                'access' => KnowledgeSource::ACCESS_LICENSED,
                'notes' => 'Licensed labour-time and repair data — the natural source for book '
                    .'times once subscribed.',
            ],
            [
                'key' => 'haynes',
                'name' => 'Haynes Manuals',
                'publisher' => 'Haynes Publishing',
                'tier' => KnowledgeSource::TIER_REFERENCE,
                'trust_weight' => 78,
                'access' => KnowledgeSource::ACCESS_LICENSED,
                'notes' => 'Copyrighted repair manuals. Purchase/licence required; ingest owned '
                    .'copies as uploaded documents.',
            ],
            [
                'key' => 'chilton',
                'name' => 'Chilton Repair Manuals',
                'publisher' => 'Cengage',
                'tier' => KnowledgeSource::TIER_REFERENCE,
                'trust_weight' => 76,
                'access' => KnowledgeSource::ACCESS_LICENSED,
                'notes' => 'Copyrighted repair manuals. Licence required.',
            ],

            // ---- Tier 4: government / regulatory --------------------------------------------
            [
                'key' => 'nhtsa',
                'name' => 'NHTSA Safety, Recall & Complaint Database',
                'publisher' => 'US Department of Transportation',
                'tier' => KnowledgeSource::TIER_PUBLIC,
                'trust_weight' => 85,
                'access' => KnowledgeSource::ACCESS_PUBLIC_WEB,
                'domains' => ['nhtsa.gov', 'api.nhtsa.gov', 'vpic.nhtsa.dot.gov'],
                'notes' => 'Public, free, and API-accessible. Strong for recalls, defect '
                    .'investigations and real owner-complaint phrasing.',
            ],
            [
                'key' => 'gov_transport',
                'name' => 'Government Transportation Repair Documentation',
                'publisher' => 'Various transport authorities',
                'tier' => KnowledgeSource::TIER_PUBLIC,
                'trust_weight' => 80,
                'access' => KnowledgeSource::ACCESS_PUBLIC_WEB,
                'domains' => ['transportation.gov', 'rta.ae', 'moi.gov.ae', 'esma.gov.ae', 'europa.eu'],
                'notes' => 'Includes UAE authorities (RTA / ESMA), which matter for local '
                    .'inspection and roadworthiness terminology.',
            ],

            // ---- Our own data ----------------------------------------------------------------
            [
                'key' => 'fleet_history',
                'name' => 'FleetView Maintenance History',
                'publisher' => 'Internal',
                'tier' => KnowledgeSource::TIER_FLEET,
                'trust_weight' => 95,
                'access' => KnowledgeSource::ACCESS_DERIVED,
                'notes' => 'Our own closed tickets and historical maintenance records. Not '
                    .'documentation — observation — and for this fleet it outranks most of it. '
                    .'Mined by FleetEvidenceService.',
            ],
            [
                'key' => 'fleet_uploads',
                'name' => 'Uploaded Manuals & Internal Procedures',
                'publisher' => 'Internal',
                'tier' => KnowledgeSource::TIER_REFERENCE,
                'trust_weight' => 82,
                'access' => KnowledgeSource::ACCESS_UPLOADED,
                'notes' => 'Documents the fleet owns and has ingested via `knowledge:ingest` — '
                    .'purchased manuals, garage procedures, supplier guides.',
            ],
        ];
    }
}
