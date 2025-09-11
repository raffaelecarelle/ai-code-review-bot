# Project: AI Code Review Bot (Minimal, Extensible)

This repository contains a minimal yet extensible AI-assisted code review tool that:
- Analyzes unified diffs (from Pull/Merge Requests or files).
- Produces normalized findings (JSON or human summary) you can post via CI.
- Loads a project configuration file (YAML/JSON) with regex rules and provider/policy settings.
- Falls back to sensible defaults and a deterministic Mock AI provider (no external calls by default).

Recent highlights
- Git-based diff mode with dynamic branch resolution from a PR/MR ID via adapters (GitHub/GitLab).
- Single --id option; the configured vcs.platform decides whether to use GitHub or GitLab.
- Optional --comment to auto-comment on the PR/MR. When config test: true, the comment is printed instead of posted.
- All git invocations use Symfony Process (no exec).

Index
- 1. Objectives and scope
- 2. Architecture and main modules
- 3. Quick start
- 4. Configuration (.aicodereview.yml)
- 5. VCS adapters (GitHub/GitLab)
- 6. Rules engine
- 7. AI providers and token budgeting
- 8. Output formats
- 9. Development and QA


## 1. Objectives and scope
- Functional
  - Analyze diffs and produce review findings for coding standard violations and simple risk patterns.
  - Dynamic configuration for providers, policy, token budget, rules, and VCS.
  - Post results back to PR/MR via platform adapters when requested.
- Non-functional
  - Safe defaults: no external calls by default (mock provider) and no PR comments unless --comment.
  - Modular design to plug real LLM providers and VCS platforms.


## 2. Architecture and main modules (PHP)
- bin/aicr: CLI entry point (Symfony Console) running the review command in single-command mode.
- src/Command/ReviewCommand.php: Orchestrates reading config, loading diff (from file or git), running Pipeline, and optional PR/MR commenting. Uses Symfony Process for git.
- src/Config.php: Loads YAML/JSON config, merges with defaults, expands ${ENV} variables, exposes sections (providers, context, policy, rules, vcs, test).
- src/DiffParser.php: Minimal unified diff parser returning added lines per file with accurate line numbers.
- src/Pipeline.php: End-to-end pipeline: parse diff, apply rules, build AI provider, chunk with token budget, apply policy, and render output.
- src/Adapters/: VcsAdapter interface and GithubAdapter/GitlabAdapter implementations (resolve branches from PR/MR id and post comments).
- src/Providers/: AIProvider interface and concrete providers (OpenAI, Gemini, Anthropic, Ollama, Mock).


## 3. Quick start
- Install dependencies via Composer:

  composer install

- Option A: Analyze an existing diff file
  - Create or use a unified diff, e.g., examples/sample.diff.
  - Run:

    php bin/aicr review --diff-file examples/sample.diff --output summary

    php bin/aicr review --diff-file examples/sample.diff --output json

- Option B: Analyze a PR/MR by ID using git
  - Configure vcs.platform in .aicodereview.yml (github or gitlab) and set required identifiers/tokens.
  - Then run (the command fetches branches, computes diff, and analyzes it):

    php bin/aicr review --id 123 --output summary

  - To also post a comment back to the PR/MR, add --comment. If test: true in config, the comment body is printed instead of posted:

    php bin/aicr review --id 123 --output summary --comment

Notes
- Provide --config <path> to use a non-default config file.
- Without --diff-file, --id is required and branches are resolved via the configured adapter.


## 4. Configuration (.aicodereview.yml)
Example (see .aicodereview.yml in this repo and examples/config.*.yml):

version: 1
# When true and --comment is passed, print the comment instead of posting to the platform
test: false
providers:
  # Safe deterministic provider by default
  default: mock
context:
  diff_token_limit: 8000
  overflow_strategy: trim
  per_file_token_cap: 2000
policy:
  min_severity_to_comment: info
  max_comments: 50
  allow_suggested_fixes: true
  redact_secrets: true
rules:
  include: []
  inline:
    - id: PHP.NO.ECHO
      applies_to: ["**/*.php"]
      severity: minor
      rationale: Avoid direct echo in production code; use a logger with levels instead.
      pattern: "(^|\\s)echo\\s"
      suggestion: "Use a configurable logger or framework response API."
      enabled: true
vcs:
  # Set one of: github | gitlab
  platform: null
  # GitHub: owner/repo (optional if GH_REPO env or remote origin is GitHub)
  repo: null
  # GitLab: numeric id or full path namespace/repo (optional if GL_PROJECT_ID or remote origin is GitLab)
  project_id: null
  # GitLab: override API base for self-hosted instances (e.g., https://gitlab.example.com/api/v4)
  api_base: null
prompts:
  # Optional: append additional instructions to the base prompts used by the LLM
  # You can use single strings or lists of strings
  system_append: "Prefer concise findings and avoid duplicates."
  user_append:
    - "Prioritize security and performance related issues."
  extra:
    - "If a secret or key is detected, suggest redaction."

Notes
- Env var expansion works in any string value: ${VAR_NAME}.
- Tokens/ids read from env if not set: GH_TOKEN/GITHUB_TOKEN, GL_TOKEN/GITLAB_TOKEN, GH_REPO, GL_PROJECT_ID.


## 5. VCS adapters (GitHub/GitLab)
- Configure vcs.platform and repo/project_id as needed.
- The review command supports a single --id option (PR number for GitHub, MR IID for GitLab).
- Behavior when --diff-file is omitted:
  1) Resolve base/head branches from the ID via platform API.
  2) git fetch --all; fetch base/head; compute git diff base...head.
  3) Run the analysis pipeline on that diff.
- --comment posts the summary back via the adapter unless test: true, in which case it prints the comment.

Environment variables
- GitHub: GH_TOKEN or GITHUB_TOKEN; GH_REPO can override repo auto-detection.
- GitLab: GL_TOKEN or GITLAB_TOKEN; GL_PROJECT_ID and optional GL_API_BASE.


## 6. Rules engine
- Rules are regex-based and apply only to added lines in the diff for precise signals.
- You can inline rules in config or include external rule files via rules.include.


## 7. AI providers and token budgeting
- Supported providers in this repository: openai, gemini, anthropic, ollama, mock.
- Select via providers.default and configure each provider section accordingly (see src/Providers/* for options).
- Token budgeting is approximate (chars/4). Global and per-file caps are configurable; overflow_strategy defaults to trim.


## 8. Output formats
- json (default): machine-readable findings array.
- summary: human-readable bulleted list. This is also the format used for PR/MR comments.


## 9. Development and QA
- Requires PHP and Composer.
- Run unit and E2E tests with PHPUnit:

  ./vendor/bin/phpunit

- Coding standards and static analysis:

  composer analyse

- The codebase uses declare(strict_types=1) and Symfony components (Console, YAML, Filesystem, Process).

Contributions and questions
- Please open issues or PRs.
