<?php

class WeatherService
{
    private string $apiKey;
    private string $baseUrl = 'https://api.openweathermap.org/data/2.5';
    private int $cacheDuration = 1800; // 30 minutes

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey ?? ($_ENV['OPENWEATHER_API_KEY'] ?? 'e0a2b0e88c8c4b1a9b6f3e2d1c0a9b8f');
    }

    public function getWeather(float $lat, float $lon): ?array
    {
        if ($lat == 0 && $lon == 0) return null;

        $cacheKey = 'weather_' . $lat . '_' . $lon;
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) return $cached;

        $url = "{$this->baseUrl}/weather?lat={$lat}&lon={$lon}&appid={$this->apiKey}&units=metric";
        $data = $this->fetch($url);
        if (!$data) return null;

        $forecastUrl = "{$this->baseUrl}/forecast?lat={$lat}&lon={$lon}&appid={$this->apiKey}&units=metric";
        $forecast = $this->fetch($forecastUrl);

        $result = $this->parseWeather($data, $forecast);
        $this->setCache($cacheKey, $result);

        return $result;
    }

    public function getAdvisory(array $weather): array
    {
        $condition = strtolower($weather['condition'] ?? '');
        $windSpeed = $weather['wind_speed'] ?? 0;
        $rainChance = $weather['rain_chance'] ?? 0;
        $temp = $weather['temperature'] ?? 25;
        $isRaining = $weather['is_raining'] ?? false;
        $rainVolume = $weather['rain_1h'] ?? 0;

        // Heavy rain / thunderstorm / flooding / strong wind
        if (in_array($condition, ['thunderstorm', 'heavy rain', 'torrential rain', 'tornado', 'hurricane', 'cyclone'])
            || ($isRaining && $rainVolume >= 7.5)
            || ($rainChance >= 70 && $windSpeed >= 40)
            || $windSpeed >= 50) {
            return [
                'level'   => 'danger',
                'badge'   => 'Not Recommended',
                'icon'    => 'fa-triangle-exclamation',
                'color'   => '#ef4444',
                'bg'      => 'rgba(239,68,68,0.1)',
                'border'  => 'rgba(239,68,68,0.2)',
                'message' => 'Due to adverse weather conditions, access to this destination may be difficult or unsafe. Travel is not recommended today. Please wait for better weather conditions before visiting.',
                'nav_disabled' => true,
            ];
        }

        // Light rain / cloudy / overcast
        if (in_array($condition, ['light rain', 'drizzle', 'mist', 'fog', 'overcast clouds', 'scattered clouds', 'broken clouds', 'few clouds', 'cloudy'])
            || ($isRaining && $rainVolume < 7.5)
            || ($rainChance >= 30 && $rainChance < 70)) {
            return [
                'level'   => 'warning',
                'badge'   => 'Travel with Caution',
                'icon'    => 'fa-cloud-showers-heavy',
                'color'   => '#f59e0b',
                'bg'      => 'rgba(245,158,11,0.1)',
                'border'  => 'rgba(245,158,11,0.2)',
                'message' => 'Light rain or cloudy conditions are expected. You can still visit the destination, but bringing an umbrella or raincoat is recommended.',
                'nav_disabled' => false,
            ];
        }

        // Sunny / clear / good weather
        return [
            'level'   => 'success',
            'badge'   => 'Best Time to Visit',
            'icon'    => 'fa-sun',
            'color'   => '#10b981',
            'bg'      => 'rgba(16,185,129,0.1)',
            'border'  => 'rgba(16,185,129,0.2)',
            'message' => 'The weather is clear and pleasant, making today an excellent time to visit. Enjoy sightseeing, photography, swimming, and other outdoor activities.',
            'nav_disabled' => false,
        ];
    }

    private function parseWeather(array $current, ?array $forecast): array
    {
        $weather = $current['weather'][0] ?? [];
        $main = $current['main'] ?? [];
        $wind = $current['wind'] ?? [];
        $rain = $current['rain'] ?? [];

        $condition = $weather['main'] ?? 'Unknown';
        $description = ucfirst($weather['description'] ?? 'Unknown');
        $icon = $weather['icon'] ?? '01d';

        $rain1h = $rain['1h'] ?? 0;
        $isRaining = in_array($condition, ['Rain', 'Drizzle', 'Thunderstorm']) || $rain1h > 0;

        $rainChance = 0;
        if ($forecast && !empty($forecast['list'])) {
            $next8 = array_slice($forecast['list'], 0, 3);
            $maxPop = 0;
            foreach ($next8 as $f) {
                $pop = ($f['pop'] ?? 0) * 100;
                if ($pop > $maxPop) $maxPop = $pop;
            }
            $rainChance = (int) round($maxPop);
        }

        $sunrise = $current['sys']['sunrise'] ?? 0;
        $sunset = $current['sys']['sunset'] ?? 0;
        $now = time();
        $isDay = ($sunrise <= $now && $now <= $sunset);

        return [
            'condition'     => $condition,
            'description'   => $description,
            'icon_url'      => "https://openweathermap.org/img/wn/{$icon}@2x.png",
            'icon'          => $icon,
            'is_day'        => $isDay,
            'temperature'   => round($main['temp'] ?? 0, 1),
            'feels_like'    => round($main['feels_like'] ?? 0, 1),
            'humidity'      => (int)($main['humidity'] ?? 0),
            'wind_speed'    => round(($wind['speed'] ?? 0) * 3.6, 1), // m/s to km/h
            'wind_deg'      => (int)($wind['deg'] ?? 0),
            'pressure'      => (int)($main['pressure'] ?? 0),
            'visibility'    => round(($current['visibility'] ?? 10000) / 1000, 1),
            'rain_chance'   => $rainChance,
            'is_raining'    => $isRaining,
            'rain_1h'       => $rain1h,
            'cloudiness'    => (int)($current['clouds']['all'] ?? 0),
            'temp_min'      => round($main['temp_min'] ?? 0, 1),
            'temp_max'      => round($main['temp_max'] ?? 0, 1),
            'sunrise'       => $sunrise ? date('H:i', $sunrise) : null,
            'sunset'        => $sunset ? date('H:i', $sunset) : null,
            'city'          => $current['name'] ?? '',
            'country'       => $current['sys']['country'] ?? '',
            'fetched_at'    => date('Y-m-d H:i:s'),
        ];
    }

    private function fetch(string $url): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) return null;

        $data = json_decode($response, true);
        return is_array($data) ? $data : null;
    }

    private function getCache(string $key): ?array
    {
        $file = sys_get_temp_dir() . '/weather_' . md5($key) . '.json';
        if (!file_exists($file)) return null;
        $age = time() - filemtime($file);
        if ($age > $this->cacheDuration) { @unlink($file); return null; }
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    private function setCache(string $key, array $data): void
    {
        $file = sys_get_temp_dir() . '/weather_' . md5($key) . '.json';
        @file_put_contents($file, json_encode($data));
    }

    public static function getWindDirection(int $degrees): string
    {
        $dirs = ['N','NNE','NE','ENE','E','ESE','SE','SSE','S','SSW','SW','WSW','W','WNW','NW','NNW'];
        return $dirs[round($degrees / 22.5) % 16];
    }

    public static function getConditionIcon(string $condition): string
    {
        return match(strtolower($condition)) {
            'clear'         => 'fa-sun',
            'clouds'        => 'fa-cloud',
            'few clouds'    => 'fa-cloud-sun',
            'scattered clouds' => 'fa-cloud',
            'broken clouds' => 'fa-cloud',
            'overcast clouds' => 'fa-cloud',
            'rain'          => 'fa-cloud-rain',
            'light rain'    => 'fa-cloud-sun-rain',
            'drizzle'       => 'fa-cloud-drizzle',
            'thunderstorm'  => 'fa-cloud-bolt',
            'snow'          => 'fa-snowflake',
            'mist'          => 'fa-smog',
            'fog'           => 'fa-smog',
            'haze'          => 'fa-smog',
            default         => 'fa-cloud',
        };
    }
}
