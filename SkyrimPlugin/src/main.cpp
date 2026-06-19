#include <SkyrimScripting/Plugin.h>

#include <RE/Skyrim.h>
#include <RE/T/TESDataHandler.h>

#include <algorithm>
#include <atomic>
#include <chrono>
#include <cctype>
#include <fstream>
#include <iomanip>
#include <sstream>
#include <string>
#include <thread>
#include <unordered_map>
#include <vector>

#ifndef WIN32_LEAN_AND_MEAN
#define WIN32_LEAN_AND_MEAN
#endif
#ifndef NOMINMAX
#define NOMINMAX
#endif
#include <windows.h>
#include <winhttp.h>

namespace
{
    constexpr const char* kPluginId = "CHIM-Custom";
    constexpr const char* kPluginVersion = "0.1.0";
    constexpr const char* kDirtAndBloodPlugin = "Dirt and Blood - Dynamic Visuals.esp";

    struct ServerSettings
    {
        std::string server{"127.0.0.1"};
        std::string port{"8081"};
        std::string configPath{"/HerikaServer/ext/CHIM-Custom/api/config.php"};
        std::string statePath{"/HerikaServer/ext/CHIM-Custom/api/state.php"};
        int pollSeconds{8};
    };

    struct DirtAndBloodState
    {
        bool available{false};
        int dirtLevel{0};
        int bloodLevel{0};
        bool isClean{false};
        bool isWashing{false};
    };

    std::atomic_bool g_monitorRunning{false};

    std::string Trim(std::string value)
    {
        auto isSpace = [](unsigned char ch) { return std::isspace(ch) != 0; };
        value.erase(value.begin(), std::find_if(value.begin(), value.end(), [&](unsigned char ch) { return !isSpace(ch); }));
        value.erase(std::find_if(value.rbegin(), value.rend(), [&](unsigned char ch) { return !isSpace(ch); }).base(), value.end());
        return value;
    }

    std::string ToUpperAscii(std::string value)
    {
        std::transform(value.begin(), value.end(), value.begin(), [](unsigned char ch) {
            return static_cast<char>(std::toupper(ch));
        });
        return value;
    }

    std::wstring ToWide(const std::string& value)
    {
        if (value.empty()) {
            return L"";
        }

        int size = MultiByteToWideChar(CP_UTF8, 0, value.c_str(), -1, nullptr, 0);
        if (size <= 0) {
            return std::wstring(value.begin(), value.end());
        }

        std::wstring result(static_cast<std::size_t>(size - 1), L'\0');
        MultiByteToWideChar(CP_UTF8, 0, value.c_str(), -1, result.data(), size);
        return result;
    }

    std::string JsonEscape(const std::string& value)
    {
        std::ostringstream out;
        for (unsigned char ch : value) {
            switch (ch) {
                case '\\':
                    out << "\\\\";
                    break;
                case '"':
                    out << "\\\"";
                    break;
                case '\n':
                    out << "\\n";
                    break;
                case '\r':
                    out << "\\r";
                    break;
                case '\t':
                    out << "\\t";
                    break;
                default:
                    if (ch < 0x20) {
                        out << "\\u" << std::hex << std::setw(4) << std::setfill('0') << static_cast<int>(ch);
                    } else {
                        out << static_cast<char>(ch);
                    }
                    break;
            }
        }
        return out.str();
    }

    std::unordered_map<std::string, std::string> ReadKeyValueFile(const std::string& path)
    {
        std::unordered_map<std::string, std::string> values;
        std::ifstream file(path);
        if (!file.is_open()) {
            return values;
        }

        std::string line;
        while (std::getline(file, line)) {
            line = Trim(line);
            if (line.empty() || line[0] == ';' || line[0] == '#' || line[0] == '[') {
                continue;
            }

            auto pos = line.find('=');
            if (pos == std::string::npos) {
                continue;
            }

            std::string key = ToUpperAscii(Trim(line.substr(0, pos)));
            std::string value = Trim(line.substr(pos + 1));
            if (value.size() >= 2 && ((value.front() == '"' && value.back() == '"') || (value.front() == '\'' && value.back() == '\''))) {
                value = value.substr(1, value.size() - 2);
            }
            values[key] = value;
        }

        return values;
    }

    void ApplySettingsFile(ServerSettings& settings, const std::string& path)
    {
        auto values = ReadKeyValueFile(path);
        if (values.empty()) {
            return;
        }

        if (!values["SERVER"].empty()) {
            settings.server = values["SERVER"];
        }
        if (!values["PORT"].empty()) {
            settings.port = values["PORT"];
        }
        if (!values["CONFIG_PATH"].empty()) {
            settings.configPath = values["CONFIG_PATH"];
        }
        if (!values["STATE_PATH"].empty()) {
            settings.statePath = values["STATE_PATH"];
        }
        if (!values["POLL_SECONDS"].empty()) {
            try {
                settings.pollSeconds = std::max(2, std::min(120, std::stoi(values["POLL_SECONDS"])));
            } catch (...) {
                settings.pollSeconds = 8;
            }
        }
    }

    ServerSettings LoadServerSettings()
    {
        ServerSettings settings;
        ApplySettingsFile(settings, "AIAgent.ini");
        ApplySettingsFile(settings, "Data\\SKSE\\Plugins\\AIAgent.ini");
        ApplySettingsFile(settings, "Data\\SKSE\\Plugins\\CHIMCustom.ini");
        return settings;
    }

    INTERNET_PORT ParsePort(const std::string& port)
    {
        try {
            int parsed = std::stoi(port);
            if (parsed > 0 && parsed <= 65535) {
                return static_cast<INTERNET_PORT>(parsed);
            }
        } catch (...) {
        }
        return 8081;
    }

    bool HttpRequest(const ServerSettings& settings, const std::string& method, const std::string& path, const std::string& body, std::string* responseOut)
    {
        auto session = WinHttpOpen(L"CHIM-Custom/0.1", WINHTTP_ACCESS_TYPE_DEFAULT_PROXY, WINHTTP_NO_PROXY_NAME, WINHTTP_NO_PROXY_BYPASS, 0);
        if (!session) {
            return false;
        }

        auto host = ToWide(settings.server);
        auto endpoint = ToWide(path);
        HINTERNET connect = WinHttpConnect(session, host.c_str(), ParsePort(settings.port), 0);
        if (!connect) {
            WinHttpCloseHandle(session);
            return false;
        }

        auto methodWide = ToWide(method);
        HINTERNET request = WinHttpOpenRequest(connect, methodWide.c_str(), endpoint.c_str(), nullptr, WINHTTP_NO_REFERER, WINHTTP_DEFAULT_ACCEPT_TYPES, 0);
        if (!request) {
            WinHttpCloseHandle(connect);
            WinHttpCloseHandle(session);
            return false;
        }

        std::wstring headers = L"Content-Type: application/json\r\n";
        DWORD bodySize = static_cast<DWORD>(body.size());
        BOOL sent = WinHttpSendRequest(
            request,
            headers.c_str(),
            static_cast<DWORD>(headers.size()),
            body.empty() ? WINHTTP_NO_REQUEST_DATA : const_cast<char*>(body.data()),
            bodySize,
            bodySize,
            0);

        bool ok = false;
        if (sent && WinHttpReceiveResponse(request, nullptr)) {
            ok = true;
            if (responseOut) {
                responseOut->clear();
                DWORD available = 0;
                while (WinHttpQueryDataAvailable(request, &available) && available > 0) {
                    std::string buffer;
                    buffer.resize(available);
                    DWORD read = 0;
                    if (!WinHttpReadData(request, buffer.data(), available, &read) || read == 0) {
                        break;
                    }
                    buffer.resize(read);
                    responseOut->append(buffer);
                }
            }
        }

        WinHttpCloseHandle(request);
        WinHttpCloseHandle(connect);
        WinHttpCloseHandle(session);
        return ok;
    }

    bool ParseDirtAndBloodEnabled(const std::string& configResponse)
    {
        auto idPos = configResponse.find("\"integration_id\":\"dirt_and_blood\"");
        if (idPos == std::string::npos) {
            return true;
        }

        auto enabledPos = configResponse.find("\"enabled\":", idPos);
        if (enabledPos == std::string::npos) {
            return true;
        }

        auto valuePos = configResponse.find_first_not_of(" \t\r\n", enabledPos + 10);
        if (valuePos == std::string::npos) {
            return true;
        }

        return configResponse.compare(valuePos, 5, "false") != 0;
    }

    RE::SpellItem* LookupSpell(RE::TESDataHandler* dataHandler, RE::FormID localFormId)
    {
        if (!dataHandler) {
            return nullptr;
        }
        return dataHandler->LookupForm<RE::SpellItem>(localFormId, kDirtAndBloodPlugin);
    }

    DirtAndBloodState ReadDirtAndBloodState(RE::Actor* actor)
    {
        DirtAndBloodState state;
        if (!actor) {
            return state;
        }

        auto* dataHandler = RE::TESDataHandler::GetSingleton();
        if (!dataHandler) {
            return state;
        }

        std::vector<RE::SpellItem*> dirtSpells = {
            LookupSpell(dataHandler, 0x000806),
            LookupSpell(dataHandler, 0x000807),
            LookupSpell(dataHandler, 0x000808),
            LookupSpell(dataHandler, 0x000838),
        };
        std::vector<RE::SpellItem*> bloodSpells = {
            LookupSpell(dataHandler, 0x000809),
            LookupSpell(dataHandler, 0x00080A),
            LookupSpell(dataHandler, 0x00080B),
            LookupSpell(dataHandler, 0x000839),
        };

        auto* cleanSpell = LookupSpell(dataHandler, 0x00080C);
        auto* washingSpell = LookupSpell(dataHandler, 0x00081C);

        state.available = cleanSpell || washingSpell;
        for (auto* spell : dirtSpells) {
            state.available = state.available || spell;
        }
        for (auto* spell : bloodSpells) {
            state.available = state.available || spell;
        }
        if (!state.available) {
            return state;
        }

        auto hasSpell = [actor](RE::SpellItem* spell) -> bool {
            if (!spell) {
                return false;
            }
            try {
                return actor->HasSpell(spell);
            } catch (...) {
                return false;
            }
        };

        for (std::size_t i = 0; i < dirtSpells.size(); ++i) {
            if (hasSpell(dirtSpells[i])) {
                state.dirtLevel = static_cast<int>(i) + 1;
            }
        }
        for (std::size_t i = 0; i < bloodSpells.size(); ++i) {
            if (hasSpell(bloodSpells[i])) {
                state.bloodLevel = static_cast<int>(i) + 1;
            }
        }

        state.isClean = hasSpell(cleanSpell) && state.dirtLevel == 0 && state.bloodLevel == 0;
        state.isWashing = hasSpell(washingSpell);
        return state;
    }

    std::string FormIdHex(RE::FormID formId)
    {
        std::ostringstream out;
        out << std::uppercase << std::hex << std::setw(8) << std::setfill('0') << formId;
        return out.str();
    }

    std::string BuildStatePayload(RE::PlayerCharacter* player, const DirtAndBloodState& state)
    {
        std::string playerName = "Player";
        if (player && player->GetDisplayFullName() && player->GetDisplayFullName()[0]) {
            playerName = player->GetDisplayFullName();
        }

        std::ostringstream body;
        body << "{";
        body << "\"plugin_id\":\"" << kPluginId << "\",";
        body << "\"plugin_version\":\"" << kPluginVersion << "\",";
        body << "\"game_version\":\"skyrim\",";
        body << "\"states\":[";
        body << "{";
        body << "\"integration_id\":\"dirt_and_blood\",";
        body << "\"actor_name\":\"" << JsonEscape(playerName) << "\",";
        body << "\"actor_type\":\"player\",";
        body << "\"runtime_formid\":\"" << FormIdHex(player ? player->GetFormID() : 0) << "\",";
        body << "\"gamets\":0,";
        body << "\"state\":{";
        body << "\"source_mod\":\"" << kDirtAndBloodPlugin << "\",";
        body << "\"dirt_level\":" << state.dirtLevel << ",";
        body << "\"blood_level\":" << state.bloodLevel << ",";
        body << "\"is_clean\":" << (state.isClean ? "true" : "false") << ",";
        body << "\"is_washing\":" << (state.isWashing ? "true" : "false");
        body << "}";
        body << "}";
        body << "]";
        body << "}";
        return body.str();
    }

    std::string BuildHeartbeatPayload()
    {
        std::ostringstream body;
        body << "{";
        body << "\"plugin_id\":\"" << kPluginId << "\",";
        body << "\"plugin_version\":\"" << kPluginVersion << "\",";
        body << "\"game_version\":\"skyrim\",";
        body << "\"states\":[]";
        body << "}";
        return body.str();
    }

    void MonitorLoop()
    {
        bool dirtAndBloodEnabled = true;
        bool loggedAvailable = false;
        std::string lastHash;
        auto nextConfigRefresh = std::chrono::steady_clock::now();
        auto nextHeartbeatPost = std::chrono::steady_clock::now();

        while (g_monitorRunning.load()) {
            ServerSettings settings = LoadServerSettings();
            auto now = std::chrono::steady_clock::now();

            if (now >= nextConfigRefresh) {
                std::string configResponse;
                if (HttpRequest(settings, "GET", settings.configPath, "", &configResponse)) {
                    dirtAndBloodEnabled = ParseDirtAndBloodEnabled(configResponse);
                }
                nextConfigRefresh = now + std::chrono::seconds(60);
            }

            if (now >= nextHeartbeatPost) {
                HttpRequest(settings, "POST", settings.statePath, BuildHeartbeatPayload(), nullptr);
                nextHeartbeatPost = now + std::chrono::seconds(60);
            }

            auto* player = RE::PlayerCharacter::GetSingleton();
            if (player && dirtAndBloodEnabled) {
                DirtAndBloodState state = ReadDirtAndBloodState(player);
                if (state.available) {
                    if (!loggedAvailable) {
                        logger::info("[CHIM-Custom] Dirt and Blood detected");
                        loggedAvailable = true;
                    }

                    std::string hash = std::to_string(state.dirtLevel) + "|" +
                        std::to_string(state.bloodLevel) + "|" +
                        (state.isClean ? "1" : "0") + "|" +
                        (state.isWashing ? "1" : "0");

                    if (hash != lastHash) {
                        lastHash = hash;
                        HttpRequest(settings, "POST", settings.statePath, BuildStatePayload(player, state), nullptr);
                        nextHeartbeatPost = std::chrono::steady_clock::now() + std::chrono::seconds(60);
                    }
                }
            }

            int sleepSeconds = std::max(2, settings.pollSeconds);
            for (int i = 0; i < sleepSeconds && g_monitorRunning.load(); ++i) {
                std::this_thread::sleep_for(std::chrono::seconds(1));
            }
        }
    }

    void StartMonitor()
    {
        bool expected = false;
        if (!g_monitorRunning.compare_exchange_strong(expected, true)) {
            return;
        }

        std::thread([]() {
            logger::info("[CHIM-Custom] monitor started");
            MonitorLoop();
            logger::info("[CHIM-Custom] monitor stopped");
        }).detach();
    }

    void StopMonitor()
    {
        g_monitorRunning.store(false);
    }
}

OnLoadedGame
{
    StartMonitor();
}

OnNewGame
{
    StartMonitor();
}

OnLoadingGame
{
    StopMonitor();
}
