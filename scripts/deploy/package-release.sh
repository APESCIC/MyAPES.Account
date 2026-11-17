#!/usr/bin/env bash
set -euo pipefail

if [[ -z "${RELEASE_SHA:-}" ]]; then
  echo "RELEASE_SHA is required to package a release."
  exit 1
fi

if [[ -z "${TRUSTED_CONTROLS_SHA256:-}" ]]; then
  echo "TRUSTED_CONTROLS_SHA256 is required to package a release."
  exit 1
fi

mkdir -p build/release
rsync -a ./ build/release/ \
  --exclude '/.git/' \
  --exclude '/.github/' \
  --exclude '/.env' \
  --exclude '/.env.*' \
  --exclude '/build/' \
  --exclude '/design-qa.md' \
  --exclude '/docs/' \
  --exclude '/node_modules/' \
  --exclude '/scripts/local/' \
  --exclude '/storage/' \
  --exclude '/tests/' \
  --exclude '/public/hot' \
  --exclude '/public/storage/avatars' \
  --exclude '/public/storage/pet-profiles' \
  --exclude '/.phpunit.cache/' \
  --exclude '/.phpunit.result.cache'

bash scripts/deploy/validate-selective-media-archive.sh source build/release

printf '%s\n' "$RELEASE_SHA" > build/release/REVISION
deployment_control_paths=(
  scripts/deploy/activate-release.sh
  scripts/deploy/rollback-release.sh
  scripts/deploy/cloudron-app.conf
  scripts/deploy/cloudron-run.sh
)
: > build/release/DEPLOYMENT-CONTROLS.sha256
for control_path in "${deployment_control_paths[@]}"; do
  control_digest="$(sha256sum "build/release/$control_path" | awk '{print $1}')"
  printf '%s  %s\n' "$control_digest" "$control_path" \
    >> build/release/DEPLOYMENT-CONTROLS.sha256
done
deployment_controls_sha256="$(sha256sum build/release/DEPLOYMENT-CONTROLS.sha256 | awk '{print $1}')"
[[ "$deployment_controls_sha256" =~ ^[0-9a-f]{64}$ ]]
[[ "$deployment_controls_sha256" == "$TRUSTED_CONTROLS_SHA256" ]]
tar --create --gzip --file "build/myapes-$RELEASE_SHA.tar.gz" --directory build/release .
test -s "build/myapes-$RELEASE_SHA.tar.gz"
bash scripts/deploy/validate-selective-media-archive.sh \
  archive "build/myapes-$RELEASE_SHA.tar.gz"

tar --list --gzip --file "build/myapes-$RELEASE_SHA.tar.gz" > build/archive-list.txt
grep -qx './REVISION' build/archive-list.txt
grep -qx './VERSION' build/archive-list.txt
grep -qx './artisan' build/archive-list.txt
grep -qx './public/build/manifest.json' build/archive-list.txt
grep -qx './public/storage/.myapes-selective-media' build/archive-list.txt
grep -qx './resources/data/releases.json' build/archive-list.txt
grep -q './changelog/releases/' build/archive-list.txt
grep -qx './resources/data/module-runtime-contract.json' build/archive-list.txt
grep -qx './config/modules.php' build/archive-list.txt
grep -qx './config/permission.php' build/archive-list.txt
grep -qx './database/migrations/2026_07_28_000000_create_permission_tables.php' build/archive-list.txt
grep -qx './database/migrations/2026_07_28_000100_cut_over_authorization_domain.php' build/archive-list.txt
grep -qx './database/migrations/2026_08_06_000000_create_module_installations_table.php' build/archive-list.txt
grep -qx './database/migrations/2026_07_28_000200_rewrite_morph_type_fqcns_to_aliases.php' build/archive-list.txt
grep -qx './app/Core/Providers/CoreServiceProvider.php' build/archive-list.txt
grep -qx './app/Core/Eloquent/MorphMap.php' build/archive-list.txt
grep -qx './app/Console/Commands/AuthorizationPreflight.php' build/archive-list.txt
grep -qx './app/Console/Commands/DirectorySync.php' build/archive-list.txt
grep -qx './app/Console/Commands/AuthorizationSync.php' build/archive-list.txt
grep -qx './app/Console/Commands/AuthorizationCheck.php' build/archive-list.txt
grep -qx './app/Console/Commands/ModulesPreflight.php' build/archive-list.txt
grep -qx './app/Console/Commands/ModulesSync.php' build/archive-list.txt
grep -qx './app/Console/Commands/ModulesCheck.php' build/archive-list.txt
grep -qx './app/Console/Commands/ModulesRollbackCheck.php' build/archive-list.txt
grep -qx './app/Services/ModuleRollbackCompatibilityChecker.php' build/archive-list.txt
grep -qx './modules/apes-cic/src/ApesCicServiceProvider.php' build/archive-list.txt
grep -qx './modules/pet-care-clinic/src/PetCareClinicServiceProvider.php' build/archive-list.txt
grep -qx './modules/shelter-rescue/src/ShelterRescueServiceProvider.php' build/archive-list.txt
grep -qx './plugins/tickets/src/TicketsServiceProvider.php' build/archive-list.txt
grep -qx './plugins/cases/src/CasesServiceProvider.php' build/archive-list.txt
grep -qx './plugins/recruitment/src/RecruitmentServiceProvider.php' build/archive-list.txt
grep -qx './plugins/consultations/src/ConsultationsServiceProvider.php' build/archive-list.txt
grep -qx './plugins/pet-profiles/src/PetProfilesServiceProvider.php' build/archive-list.txt
grep -qx './scripts/deploy/activate-release.sh' build/archive-list.txt
grep -qx './scripts/deploy/rollback-release.sh' build/archive-list.txt
grep -qx './scripts/deploy/cloudron-app.conf' build/archive-list.txt
grep -qx './scripts/deploy/cloudron-run.sh' build/archive-list.txt
grep -qx './scripts/deploy/production.env.example' build/archive-list.txt
grep -qx './DEPLOYMENT-CONTROLS.sha256' build/archive-list.txt

if [[ "$(grep -Fxc './public/storage/' build/archive-list.txt || true)" != 1 ]]; then
  echo "Release archive does not contain one canonical public-storage directory."
  exit 1
fi

normalized_public_storage_members="$(sed -E 's#^(\./)+##' build/archive-list.txt \
  | grep -E '^public/storage($|/)' || true)"
if [[ "$(printf '%s\n' "$normalized_public_storage_members" \
  | grep -Fxc 'public/storage/' || true)" != 1 ]]; then
  echo "Release archive contains a duplicate or alias public-storage directory."
  exit 1
fi

if printf '%s\n' "$normalized_public_storage_members" \
  | grep -Ev '^public/storage/$|^public/storage/\.myapes-selective-media$'; then
  echo "Release archive contains a non-marker public-storage member."
  exit 1
fi

if grep -Eq '^\./(\.git($|/)|\.env($|\.)|docs/|node_modules/|scripts/local/|storage/|tests/)' build/archive-list.txt; then
  echo "Release archive contains an excluded path."
  exit 1
fi
