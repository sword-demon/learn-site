#!/usr/bin/env bash
# SC-003 single-user timing smoke. Run after the testing fixture has reset
# the database and with E2E_CAPTCHA_ANSWER enabled on the API container.

set -euo pipefail

API_BASE="${API_BASE:-http://127.0.0.1:8787}"
SAMPLE_COUNT="${SAMPLE_COUNT:-20}"
THRESHOLD_MS="${THRESHOLD_MS:-2000}"
REQUIRED_PERCENT="${REQUIRED_PERCENT:-95}"
REQUEST_TIMEOUT_SECONDS="${REQUEST_TIMEOUT_SECONDS:-5}"
PERF_PHONE="${PERF_PHONE:-13900000002}"
PERF_PASSWORD="${PERF_PASSWORD:-PerfPass123!}"
CAPTCHA_ANSWER="${E2E_CAPTCHA_ANSWER:-E2-7}"
PERF_CATEGORY_ID="${PERF_CATEGORY_ID:-1}"
PERF_COURSE_ID="${PERF_COURSE_ID:-2}"
PERF_LESSON_ID="${PERF_LESSON_ID:-2}"

die() {
  printf 'PERF ERROR: %s\n' "$*" >&2
  exit 1
}

for command_name in curl php awk sort sed mktemp; do
  command -v "$command_name" >/dev/null 2>&1 || die "missing command: ${command_name}"
done

[[ "$SAMPLE_COUNT" =~ ^[0-9]+$ ]] && ((SAMPLE_COUNT >= 20)) \
  || die 'SAMPLE_COUNT must be an integer >= 20'
[[ "$THRESHOLD_MS" =~ ^[0-9]+$ ]] && ((THRESHOLD_MS > 0)) \
  || die 'THRESHOLD_MS must be a positive integer'
[[ "$REQUIRED_PERCENT" =~ ^[0-9]+$ ]] && ((REQUIRED_PERCENT >= 1 && REQUIRED_PERCENT <= 100)) \
  || die 'REQUIRED_PERCENT must be between 1 and 100'

work_dir="$(mktemp -d)"
trap 'rm -rf "$work_dir"' EXIT

json_field() {
  local path="$1"
  php -r '
    $value = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    foreach (explode(".", $argv[1]) as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) {
            fwrite(STDERR, "missing JSON field: {$argv[1]}\n");
            exit(2);
        }
        $value = $value[$key];
    }
    if (!is_scalar($value)) {
        fwrite(STDERR, "JSON field is not scalar: {$argv[1]}\n");
        exit(3);
    }
    echo (string) $value;
  ' "$path"
}

json_payload() {
  php -r '
    echo json_encode([
        "phone" => $argv[1],
        "password" => $argv[2],
        "captcha_id" => $argv[3],
        "captcha_answer" => $argv[4],
    ], JSON_THROW_ON_ERROR);
  ' "$PERF_PHONE" "$PERF_PASSWORD" "$1" "$CAPTCHA_ANSWER"
}

request_json() {
  local method="$1"
  local path="$2"
  local expected_status="$3"
  local body="${4:-}"
  local token="${5:-}"
  local response_file
  response_file="$(mktemp "${work_dir}/response.XXXXXX")"

  local args=(
    --silent
    --show-error
    --max-time "$REQUEST_TIMEOUT_SECONDS"
    --output "$response_file"
    --write-out '%{http_code}'
    --request "$method"
  )
  if [[ -n "$token" ]]; then
    args+=(-H "Authorization: Bearer ${token}")
  fi
  if [[ -n "$body" ]]; then
    args+=(-H 'Content-Type: application/json' --data "$body")
  fi

  local status
  status="$(curl "${args[@]}" "${API_BASE}${path}")" \
    || die "request failed: ${method} ${path}"
  if [[ "$status" != "$expected_status" ]]; then
    printf 'PERF ERROR: %s %s returned HTTP %s, expected %s\n' \
      "$method" "$path" "$status" "$expected_status" >&2
    sed -n '1,20p' "$response_file" >&2
    exit 1
  fi
  cat "$response_file"
}

captcha_response="$(request_json GET '/api/learner/v1/auth/captcha' 200)"
captcha_id="$(printf '%s' "$captcha_response" | json_field 'data.captcha_id')"
register_response="$(
  request_json POST '/api/learner/v1/auth/register' 200 "$(json_payload "$captcha_id")"
)"
access_token="$(printf '%s' "$register_response" | json_field 'data.access_token')"

request_json POST "/api/learner/v1/courses/${PERF_COURSE_ID}/start" 200 '' "$access_token" >/dev/null
request_json GET "/api/learner/v1/courses/${PERF_COURSE_ID}/lessons/${PERF_LESSON_ID}" \
  200 '' "$access_token" >/dev/null

all_samples_file="${work_dir}/all.ms"
: >"$all_samples_file"
overall_good=0
overall_total=0
failed=0

measure_operation() {
  local operation="$1"
  local samples_file="${work_dir}/${operation}.ms"
  local good=0
  local http_ok=0
  : >"$samples_file"

  local sample method path body token result status seconds ms
  for ((sample = 1; sample <= SAMPLE_COUNT; sample++)); do
    method='GET'
    path='/api/learner/v1/home'
    body=''
    token=''

    case "$operation" in
      browse)
        ;;
      catalog)
        path="/api/learner/v1/categories/${PERF_CATEGORY_ID}/courses"
        ;;
      favorite)
        path="/api/learner/v1/courses/${PERF_COURSE_ID}/favorite"
        token="$access_token"
        if ((sample % 2 == 1)); then
          method='POST'
        else
          method='DELETE'
        fi
        ;;
      progress)
        method='POST'
        path="/api/learner/v1/lessons/${PERF_LESSON_ID}/progress"
        body='{"content_type":"markdown","position_seconds":1,"duration_seconds":0,"completed":false}'
        token="$access_token"
        ;;
      *)
        die "unknown operation: ${operation}"
        ;;
    esac

    local args=(
      --silent
      --show-error
      --max-time "$REQUEST_TIMEOUT_SECONDS"
      --output /dev/null
      --write-out '%{http_code} %{time_total}'
      --request "$method"
    )
    if [[ -n "$token" ]]; then
      args+=(-H "Authorization: Bearer ${token}")
    fi
    if [[ -n "$body" ]]; then
      args+=(-H 'Content-Type: application/json' --data "$body")
    fi

    if ! result="$(curl "${args[@]}" "${API_BASE}${path}")"; then
      result='000 9999'
    fi
    read -r status seconds <<<"$result"
    ms="$(awk -v seconds="$seconds" 'BEGIN { printf "%.3f", seconds * 1000 }')"
    printf '%s\n' "$ms" >>"$samples_file"
    printf '%s\n' "$ms" >>"$all_samples_file"

    if [[ "$status" == '200' ]]; then
      ((http_ok += 1))
      if awk -v value="$ms" -v limit="$THRESHOLD_MS" 'BEGIN { exit !(value < limit) }'; then
        ((good += 1))
        ((overall_good += 1))
      fi
    fi
    ((overall_total += 1))
  done

  local percentile_index p95_ms percent status_label
  percentile_index=$(((SAMPLE_COUNT * REQUIRED_PERCENT + 99) / 100))
  p95_ms="$(sort -n "$samples_file" | sed -n "${percentile_index}p")"
  percent="$(awk -v good="$good" -v total="$SAMPLE_COUNT" 'BEGIN { printf "%.1f", good * 100 / total }')"
  status_label='PASS'
  if ((good * 100 < REQUIRED_PERCENT * SAMPLE_COUNT)); then
    status_label='FAIL'
    failed=1
  fi

  printf 'PERF_RESULT operation=%s samples=%d http_200=%d within_threshold=%d percent=%s p%d_ms=%s threshold_ms=%d status=%s\n' \
    "$operation" "$SAMPLE_COUNT" "$http_ok" "$good" "$percent" \
    "$REQUIRED_PERCENT" "$p95_ms" "$THRESHOLD_MS" "$status_label"
}

measure_operation browse
measure_operation catalog
measure_operation favorite
measure_operation progress

overall_index=$(((overall_total * REQUIRED_PERCENT + 99) / 100))
overall_p95_ms="$(sort -n "$all_samples_file" | sed -n "${overall_index}p")"
overall_percent="$(
  awk -v good="$overall_good" -v total="$overall_total" \
    'BEGIN { printf "%.1f", good * 100 / total }'
)"
overall_status='PASS'
if ((overall_good * 100 < REQUIRED_PERCENT * overall_total)); then
  overall_status='FAIL'
  failed=1
fi

printf 'PERF_TOTAL samples=%d within_threshold=%d percent=%s p%d_ms=%s threshold_ms=%d status=%s\n' \
  "$overall_total" "$overall_good" "$overall_percent" "$REQUIRED_PERCENT" \
  "$overall_p95_ms" "$THRESHOLD_MS" "$overall_status"
printf 'POST_RELEASE_OBSERVATION concurrency_target=200 enforced=false\n'

if ((failed != 0)); then
  exit 1
fi
