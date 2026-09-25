# clickhouse-php

PHP ClickHouse 클라이언트. 기본적으로 ClickHouse HTTP 인터페이스(8123 포트)를 통해 통신하며, 쿼리 빌더, Schema Builder, 마이그레이션 시스템, ORM을 내장하고 Laravel, ThinkPHP, Webman, Hyperf를 지원합니다.

계층형 분리 구조: `Manager → ClientInterface → PoolInterface → TransportInterface`. 클라이언트 형태, 커넥션 풀, 전송 프로토콜이 각각 인터페이스를 향해 있어 모두 교체할 수 있습니다. Native TCP 프로토콜은 전송 계층에 자리를 마련해 두었으나 아직 구현되지 않았습니다.

**简体中文** | [English](../../../README_EN.md) | **한국어** | [Русский](../ru/README.md) | [Deutsch](../de/README.md) | [Français](../fr/README.md) | [Español](../es/README.md) | [Português](../pt/README.md) | [हिन्दी](../hi/README.md) | [العربية](../ar/README.md) | [বাংলা](../bn/README.md) | [Bahasa Indonesia](../id/README.md) | [日本語](../ja/README.md)

<p align="center">
  <img src="../../pet.svg" width="160" alt="clickhouse-php 프로젝트 마스코트: 코끼리 코로 만든 작은 집">
</p>

## 프로젝트 구조

```
clickhouse-php/
├── src/
│   ├── ClickHouse.php                  # 정적 파사드 진입점
│   ├── Client/                         # 클라이언트 계층: 다중 연결, 직결 / 풀링 두 가지 형태
│   │   ├── ClientInterface.php         # query / select / insert / ping
│   │   ├── Manager.php                 # 다중 연결 관리, 지연 로딩 + 인스턴스 캐시
│   │   ├── HttpClient.php              # 직결 클라이언트, SQL 조립과 응답 파싱 담당
│   │   └── PooledClient.php            # 풀링 클라이언트, 연결 자동 대여/반납
│   ├── Query/                          # 쿼리 빌더
│   │   ├── Builder.php                 # 체이닝 API와 집계 진입점
│   │   ├── Grammar.php                 # SELECT / DELETE 문법 컴파일
│   │   ├── Expression.php              # 원시 표현식
│   │   └── Result.php                  # 읽기 전용 결과 집합
│   ├── Schema/                         # Schema Builder
│   │   ├── Builder.php                 # create / alter / drop / 메타 정보 조회
│   │   ├── Blueprint.php               # 컬럼과 엔진 파라미터 수집
│   │   ├── Column.php                  # 컬럼 정의
│   │   └── Grammar.php                 # DDL 문법 컴파일
│   ├── Migration/                      # 마이그레이션 시스템
│   │   ├── Migration.php               # 마이그레이션 기반 클래스
│   │   ├── Migrator.php                # run / rollback / refresh
│   │   └── Repository.php              # 마이그레이션 기록 테이블 읽기/쓰기와 mutation 대기
│   ├── ORM/                            # ORM
│   │   ├── Model.php                   # ActiveRecord 기반 클래스
│   │   └── Collection.php              # 읽기 전용 모델 컬렉션
│   ├── Pool/                           # 커넥션 풀
│   │   ├── PoolInterface.php           # get / put / stats / close
│   │   ├── AbstractPool.php            # 공통 풀링 로직 (카운트, 타임아웃, 보충)
│   │   ├── SwoolePool.php              # Swoole 코루틴 채널
│   │   ├── SwowPool.php                # Swow 코루틴 채널
│   │   ├── WorkermanPool.php           # Workerman 코루틴 채널
│   │   └── NoPool.php                  # FPM 전통 모드
│   ├── Transport/                      # 전송 계층
│   │   ├── TransportInterface.php      # send / close
│   │   ├── HttpTransport.php           # Guzzle HTTP, 파라미터 바인딩과 FORMAT JSON
│   │   └── TcpTransport.php            # Native TCP (계획 중)
│   ├── Support/                        # Config / Quoter / Arr
│   ├── Exceptions/                     # 예외 체계 (기반 클래스 1개 + 하위 클래스 4개)
│   ├── Laravel/                        # ServiceProvider · Facade · Artisan 명령
│   ├── ThinkPHP/                       # Service · Facade · 명령
│   ├── Webman/                         # Service · 설치 스크립트
│   └── Hyperf/                         # ConfigProvider · 코루틴 커넥션 풀 · 명령
├── tests/                              # PHPUnit 테스트, 디렉터리는 src와 동형
├── docs/                               # 설계도와 문서
└── composer.json
```

## 아키텍처 설계

<p align="center">
  <img src="architecture.svg" width="880" alt="clickhouse-php 아키텍처 설계: 진입 계층, 빌드 계층, 클라이언트 계층, 커넥션 풀 계층, 전송 계층, 지원 계층">
</p>

위에서 아래로 여섯 계층이며, 각 계층은 바로 아래 계층의 추상 인터페이스에만 의존합니다:

| 계층 | 역할 | 주요 타입 |
|----|------|----------|
| 진입 계층 | 파사드와 프레임워크 어댑터 | `ClickHouse`, 네 가지 프레임워크 어댑터 |
| 빌드 계층 | 쿼리와 DDL 조립, IO 를 발생시키지 않음 | `Query\Builder`, `Schema\Builder`, `ORM\Model`, `Migration\Migrator` |
| 클라이언트 계층 | 다중 연결 관리와 실행 진입점 | `Manager`, `HttpClient`, `PooledClient` |
| 커넥션 풀 계층 | 연결 재사용과 동시성 상한 | `PoolInterface`, `AbstractPool`, `NoPool` |
| 전송 계층 | 프로토콜 인코딩/디코딩과 오류 매핑 | `HttpTransport`, `TcpTransport`(계획 중) |
| 지원 계층 | 설정, 이스케이프, 예외, 로그 | `Support\*`, `Exceptions\*`, PSR-3 `LoggerInterface` |

## 기능 설계

<p align="center">
  <img src="features.svg" width="880" alt="clickhouse-php 기능 설계: 쿼리 빌더, Schema Builder, 마이그레이션 시스템, ORM, 커넥션 풀, 프레임워크 통합">
</p>

## 라이프사이클

<p align="center">
  <img src="lifecycle.svg" width="880" alt="clickhouse-php 라이프사이클: 쿼리 9단계 경로, 예외 분기와 마이그레이션 라이프사이클">
</p>

## 설치

```bash
composer require erikwang2013/clickhouse-php
```

## 빠른 시작

### 단독 사용(네이티브 PHP)

프레임워크에 의존하지 않습니다. `CLICKHOUSE_*` 환경 변수로 초기화하며, 한 줄이면 됩니다:

```php
use Erikwang2013\ClickHouse\ClickHouse;

ClickHouse::bootstrap();   // ClickHouse::setManager(Manager::fromEnv()) 와 동일

$rows = ClickHouse::table('logs')
    ->where('date', '>=', '2024-01-01')
    ->whereIn('level', ['error', 'warn'])
    ->orderBy('timestamp', 'desc')
    ->limit(100)
    ->get();

foreach ($rows as $row) {
    echo $row['message'];
}
```

환경 변수로 덮을 수 없는 설정(다중 연결, 커넥션 풀 튜닝)은 `Manager` 로 명시적으로 전달합니다:

```php
use Erikwang2013\ClickHouse\ClickHouse;
use Erikwang2013\ClickHouse\Client\Manager;

// 설정
$config = [
    'default' => 'clickhouse',
    'connections' => [
        'clickhouse' => [
            'driver'   => 'http',        // http(권장) 또는 native(개발 중)
            'host'     => 'localhost',
            'port'     => 8123,          // HTTP 포트, Native 는 9000
            'database' => 'default',
            'username' => 'default',
            'password' => '',
            'timeout'  => 30,
            'https'    => false,         // true 이면 HTTPS
        ],
    ],
    'pool' => [
        'min_connections'    => 2,
        'max_connections'    => 16,
        'connection_timeout' => 5.0,
    ],
];

// 초기화
$manager = new Manager($config);
ClickHouse::setManager($manager);

// 조회
$rows = ClickHouse::table('logs')
    ->where('date', '>=', '2024-01-01')
    ->whereIn('level', ['error', 'warn'])
    ->orderBy('timestamp', 'desc')
    ->limit(100)
    ->get();

foreach ($rows as $row) {
    echo $row['message'];
}

// 원시 SQL
$result = ClickHouse::query('SELECT count(*) AS cnt FROM logs WHERE date = ?', ['2024-01-01']);
echo $result->first()['cnt'];

// 집계
$count = ClickHouse::table('logs')->where('level', 'error')->count();
$avgDuration = ClickHouse::table('logs')->avg('duration');
```

### 데이터 삽입

```php
// 단일 행
ClickHouse::table('logs')->insert([
    'date'      => '2024-01-01',
    'level'     => 'info',
    'message'   => 'hello',
    'duration'  => 12.5,
]);

// 배치
ClickHouse::table('logs')->insert([
    ['date' => '2024-01-01', 'level' => 'info',  'message' => 'a', 'duration' => 1.2],
    ['date' => '2024-01-02', 'level' => 'error', 'message' => 'b', 'duration' => 3.4],
]);
```

### 테이블 생성 (Schema Builder)

```php
ClickHouse::schema()->create('logs', function ($table) {
    $table->date('date');
    $table->dateTime('timestamp');
    $table->string('level');
    $table->string('message');
    $table->float64('duration');
    $table->engine('MergeTree')
          ->partitionBy('toYYYYMM(date)')
          ->orderBy(['date', 'timestamp', 'level']);
});

// 테이블 삭제
ClickHouse::schema()->drop('logs');

// 테이블 수정
ClickHouse::schema()->alter('logs', function ($table) {
    $table->nullable('source', 'String');
});
```

### 데이터 마이그레이션

마이그레이션 파일 생성 (예: `2026_05_27_000000_create_logs_table.php`):

```php
use Erikwang2013\ClickHouse\Migration\Migration;

class CreateLogsTable extends Migration
{
    public function up(): void
    {
        $this->schema->create('logs', function ($table) {
            $table->date('date');
            $table->string('level');
            $table->engine('MergeTree')
                  ->partitionBy('toYYYYMM(date)')
                  ->orderBy(['date', 'level']);
        });
    }

    public function down(): void
    {
        $this->schema->drop('logs');
    }
}
```

마이그레이션 실행:

```php
use Erikwang2013\ClickHouse\Migration\Migrator;
use Erikwang2013\ClickHouse\Migration\Repository;

$client = ClickHouse::getManager()->connection();
$repository = new Repository($client);
$migrator = new Migrator($client, $repository, '/path/to/migrations');

$migrator->install();   // 마이그레이션 기록 테이블 생성
$migrator->run();       // 대기 중인 마이그레이션 실행
$migrator->rollback();  // 직전 배치 롤백
$migrator->refresh();   // 롤백 후 재실행
```

### ORM

```php
use Erikwang2013\ClickHouse\ORM\Model;

class Log extends Model
{
    protected string $table = 'logs';
    protected string $connection = 'default';
}

// 조회
$logs = Log::where('level', 'error')
    ->orderBy('timestamp', 'desc')
    ->limit(50)
    ->get();

// 단일 건 조회
$log = Log::find(123);

// 집계
$total = Log::where('date', '>=', '2024-01-01')->count();

// 배치 삽입
Log::insert([
    ['date' => '2024-01-01', 'level' => 'info'],
    ['date' => '2024-01-02', 'level' => 'error'],
]);
```

### 다중 연결

```php
$config = [
    'default' => 'default',
    'connections' => [
        'default' => ['driver' => 'http', 'host' => 'ch1.example.com', 'port' => 8123],
        'analytics' => ['driver' => 'http', 'host' => 'ch2.example.com', 'port' => 8123],
    ],
];

$manager = new Manager($config);

// 지정한 연결 사용
ClickHouse::connection('analytics')->table('events')->get();
ClickHouse::table('events', 'analytics')->get();
```

## 프레임워크 통합

### Laravel

설정 파일 자동 배포, Composer 가 ServiceProvider 를 자동 검색합니다.

```bash
php artisan vendor:publish --tag=clickhouse-config
```

```php
use Erikwang2013\ClickHouse\Laravel\Facades\ClickHouse;

ClickHouse::table('logs')->where('level', 'error')->get();
ClickHouse::connection('native')->select('SELECT * FROM logs LIMIT 10');
```

Artisan 명령:

```bash
php artisan clickhouse:table-list
php artisan clickhouse:migration:install
php artisan clickhouse:migration:run
```

### ThinkPHP

`app/service.php` 에서 서비스를 등록합니다:

```php
return [
    \Erikwang2013\ClickHouse\ThinkPHP\ClickHouseService::class,
];
```

```php
use think\facade\ClickHouse;

ClickHouse::table('logs')->get();
```

```bash
php think clickhouse:table-list
```

### Webman

Webman 이 플러그인 설정을 자동 로드하므로 수동 설정이 필요 없습니다.

```php
use Erikwang2013\ClickHouse\Webman\ClickHouse;

ClickHouse::table('logs')->where('level', 'error')->get();
```

### Hyperf

`ConfigProvider` 를 통해 자동 검색되며, 의존성 주입과 코루틴 커넥션 풀을 지원합니다.

```bash
php bin/hyperf.php vendor:publish erikwang2013/clickhouse-php
```

```php
use Erikwang2013\ClickHouse\Hyperf\ClickHouseConnection;

class LogController
{
    public function __construct(
        private ClickHouseConnection $clickhouse
    ) {}

    public function index()
    {
        return $this->clickhouse->table('logs')->get();
    }
}
```

## 쿼리 빌더 레퍼런스

| 메서드 | 설명 |
|------|------|
| `table($name)` / `from($name)` | 테이블 이름 지정 |
| `select([...])` / `selectRaw($expr)` | SELECT 컬럼. `select()` 의 컬럼 이름은 백틱으로 인용되며(`` `order` `` 같은 예약어 사용 가능), 별칭 `id as uid` 는 양쪽을 각각 인용합니다. 함수나 서브쿼리를 쓰려면 `selectRaw()` 또는 `Expression` 을 사용하십시오 |
| `where($col, $op, $val)` | 조건 (인자가 2개면 `$op` 기본값은 `=`). 값이 `null` 이면 자동으로 `IS NULL` / `IS NOT NULL` 로 변환 |
| `orWhere($col, $op, $val)` | OR 조건 |
| `whereIn($col, $arr)` / `whereNotIn($col, $arr)` | IN / NOT IN |
| `whereBetween($col, [$min, $max])` | BETWEEN |
| `whereNull($col)` / `whereNotNull($col)` | IS NULL / IS NOT NULL |
| `whereRaw($sql)` | 원시 WHERE (사용자 입력 전달 금지) |
| `prewhere($col, $op, $val)` | PREWHERE, 위치는 WHERE 앞 (ClickHouse 에서 가장 효과적인 스캔 축소) |
| `orderBy($col, $dir)` | 정렬 (기본 ASC) |
| `groupBy(...$cols)` | 그룹화 |
| `having($col, $op, $val)` / `havingRaw($sql)` | HAVING, 위치는 GROUP BY 뒤. `having()` 은 컬럼 이름을 식별자로 인용하므로, 집계 조건(예: `count() > 100`)은 `havingRaw()` 또는 `new Expression('count()')` 를 사용하십시오 |
| `limit($n)` / `offset($n)` | 페이징 |
| `final()` | 조회 시 중복 제거 병합 (ReplacingMergeTree 등) |
| `sample($ratio)` | SAMPLE 샘플링, 예: `sample(0.1)` |
| `settings([...])` | 쿼리 수준 SETTINGS, 예: `settings(['max_execution_time' => 30])` |
| `count()` / `sum($col)` / `avg($col)` / `min($col)` / `max($col)` | 집계 |
| `insert($data)` | 삽입 (단일 행 또는 배치). 같은 배치의 각 행은 컬럼이 일치해야 하며, 컬럼이 빠지거나 많으면 즉시 오류가 발생합니다 (값이 위치 기준으로 어긋나 들어가는 것을 방지) |
| `delete()` | 삭제 (`ALTER TABLE ... DELETE` 로 컴파일되며, **WHERE 필수**, 없으면 예외 발생) |
| `get()` | 쿼리를 실행하고 Result 반환 |
| `first()` | 첫 번째 행 반환 |
| `toSql()` | 생성된 SQL 조회 |

### 대용량 결과 집합과 스트리밍 읽기

`get()` 은 결과 집합 전체를 PHP 배열로 파싱하며, 메모리는 응답 크기의 약 7 배입니다 (실측 5 컬럼 좁은 테이블: 페이로드 93 B/행 → 디코딩 후 677 B/행, 10만 행이면 약 73 MB). 데이터가 많을 때는 `stream()` 으로 행 단위 소비하면 메모리가 결과 집합 크기와 무관해집니다:

```php
use Erikwang2013\ClickHouse\Client\StreamingClientInterface;

$client = ClickHouse::client();           // 저수준 클라이언트가 필요할 때 사용 (connection() 은 빌더를 반환)
if ($client instanceof StreamingClientInterface) {
    foreach ($client->stream('SELECT * FROM logs') as $row) {   // FORMAT JSONEachRow
        echo $row['message'], PHP_EOL;
    }
}

// FORMAT 을 직접 지정한 쿼리(CSV/TSV 등)는 raw() 로 응답 본문을 그대로 획득
$csv = $client->raw('SELECT * FROM logs FORMAT CSV');
```

풀링 모드에서도 `stream()` 은 동일하게 사용할 수 있습니다: 연결은 제너레이터 소비가 끝나거나(중간에 break 로 파괴되면) 그 시점에 반환됩니다.

### 원시 SQL 진입점

다음 진입점은 **그대로 이어 붙이는** 원시 SQL 통로이므로, 사용자 입력을 전달하는 것은 데이터베이스를 넘겨주는 것과 같습니다: `selectRaw()`, `whereRaw()`, `havingRaw()`, `new Expression($sql)`, `Blueprint::settings()` 의 값, 그리고 `$table->string('col')` 같은 컬럼 타입 문자열(`array($name, $type)`). 식별자와 값 자체는 이미 이스케이프되지만(컬럼 이름은 백틱, 값은 타입별 이스케이프), 원시 SQL 조각은 아무런 처리를 하지 않습니다.

`Expression` 은 `select()`(배열 안에 넣어서), `where()`, `prewhere()`, `having()`, `orderBy()`, `groupBy()` 에 전달할 수 있으며, `rand()`, `toStartOfHour(ts)` 같은 표현식에 사용합니다.

## Schema 컬럼 타입

| 메서드 | ClickHouse 타입 |
|------|----------------|
| `string($name)` | String |
| `fixedString($name, $len)` | FixedString(N) |
| `int8/16/32/64($name)` | Int8/16/32/64 |
| `uint8/16/32/64($name)` | UInt8/16/32/64 |
| `float32($name)` / `float64($name)` | Float32 / Float64 |
| `decimal($name, $p, $s)` | Decimal(P, S) |
| `date($name)` | Date |
| `dateTime($name)` | DateTime |
| `dateTime64($name, $p)` | DateTime64(P) |
| `uuid($name)` | UUID |
| `bool($name)` | Bool |
| `array($name, $type)` | Array(T) |
| `nullable($name, $type)` | Nullable(T) |
| `lowCardinality($name, $type)` | LowCardinality(T) |

## 설정 레퍼런스

```php
[
    'default' => 'clickhouse',
    'connections' => [
        'clickhouse' => [
            'driver'   => 'http',     // http(권장) | native(개발 중)
            'host'     => 'localhost',
            'port'     => 8123,       // HTTP 8123, Native 9000
            'database' => 'default',
            'username' => 'default',
            'password' => '',
            'timeout'  => 30,
            'https'    => false,
        ],
    ],
    'pool' => [
        'min_connections'    => 2,
        'max_connections'    => 16,
        'connection_timeout' => 5.0,
    ],
    'query_log' => false,
];
```

커넥션 풀에 대하여: `pool` 설정은 **사용 가능한 코루틴 채널이 있을 때만** 적용되며(Swoole / Swow / Workerman), FPM 같은 동기 환경에서는 이를 무시하고 직결하므로 동시성 상한이 생기지 않습니다. 또한 HTTP 드라이버는 동기 Guzzle 을 사용하므로 풀링의 실질적 이득은 "동시 연결 수 제한 + 연결 객체 재사용"이며, **자동으로 비블로킹이 되지는 않습니다** — 진짜 비블로킹이 필요하면 Swoole 의 curl 훅(`Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_NATIVE_CURL)`, 이는 `SWOOLE_HOOK_ALL` 에 포함되지 않습니다)을 직접 켜거나 hyperf/guzzle 의 CoroutineHandler 를 연결해야 합니다. `pool.driver` 로 `swoole|swow|workerman|none` 을 명시적으로 지정할 수 있습니다.

## 환경 변수

| 변수 | 기본값 | 설명 |
|------|--------|------|
| `CLICKHOUSE_CONNECTION` | default | 기본 연결 이름 |
| `CLICKHOUSE_HOST` | localhost | 호스트 주소 |
| `CLICKHOUSE_PORT` | 8123 | HTTP 포트 |
| `CLICKHOUSE_DB` | default | 데이터베이스 이름 |
| `CLICKHOUSE_USER` | default | 사용자 이름 |
| `CLICKHOUSE_PASS` | — | 비밀번호 |
| `CLICKHOUSE_TIMEOUT` | 30 | 연결 타임아웃(초) |
| `CLICKHOUSE_HTTPS` | false | HTTPS 사용 여부 |
| `CLICKHOUSE_DRIVER` | http | 드라이버 유형 |
| `CLICKHOUSE_POOL_MIN` | 2 | 최소 연결 수 |
| `CLICKHOUSE_POOL_MAX` | 16 | 최대 연결 수 |
| `CLICKHOUSE_POOL_TIMEOUT` | 5.0 | 연결 대여 타임아웃(초) |

위 변수는 네이티브 PHP 에서 `ClickHouse::bootstrap()` / `Manager::fromEnv()` 가 읽으며, 네 프레임워크의 설정 파일도 같은 변수 이름(`CLICKHOUSE_HTTPS` 포함)을 읽습니다.

## 예외 처리

```php
use Erikwang2013\ClickHouse\Exceptions\{
    ClickHouseException,
    ConnectionException,
    QueryException,
    TimeoutException,
    PoolException,
};

try {
    ClickHouse::table('logs')->get();
} catch (ConnectionException | TimeoutException $e) {
    // 연결 또는 타임아웃 문제
} catch (QueryException $e) {
    echo $e->getMessage();
    echo $e->getSql();      // 원본 SQL
} catch (ClickHouseException $e) {
    // 기타 예외
}
```

## 후원

| 위챗 | 알리페이 |
|------|--------|
| <img src="../../weixinpay.png" width="130" height="130" alt="위챗 결제"> | <img src="../../alipay.png" width="130" height="130" alt="알리페이"> |

후원해 주셔서 감사합니다!

## 라이선스

MIT License.  Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
