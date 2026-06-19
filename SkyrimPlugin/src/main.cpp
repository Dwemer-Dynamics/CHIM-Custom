#include <SkyrimScripting/Plugin.h>

#include <RE/Skyrim.h>
#include <RE/T/TESDataHandler.h>
#include <RE/T/TESGlobal.h>

#include <algorithm>
#include <atomic>
#include <chrono>
#include <cmath>
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
    constexpr const char* kPluginVersion = "0.4.1";
    constexpr const char* kDirtAndBloodPlugin = "Dirt and Blood - Dynamic Visuals.esp";
    constexpr const char* kBathingInSkyrimPlugin = "Bathing in Skyrim.esp";
    constexpr const char* kSunHelmPlugin = "SunHelmSurvival.esp";
    constexpr const char* kStarfrostPlugin = "Starfrost.esp";
    constexpr const char* kSurvivalModePlugin = "ccQDRSSE001-SurvivalMode.esl";
    constexpr const char* kSurvivalModeImprovedPlugin = "SurvivalModeImproved.esp";

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

    struct BathingInSkyrimState
    {
        bool available{false};
        bool enabled{false};
        int dirtinessTier{0};
        bool isBathing{false};
        bool isSoapy{false};
    };

    struct SunHelmState
    {
        bool available{false};
        bool enabled{false};
        bool hungerEnabled{false};
        bool thirstEnabled{false};
        bool exhaustionEnabled{false};
        bool coldEnabled{false};
        int hungerLevel{0};
        int thirstLevel{0};
        int exhaustionLevel{0};
        int coldLevel{0};
    };

    struct StarfrostState
    {
        bool available{false};
        bool enabled{false};
        bool hungerEnabled{false};
        bool exhaustionEnabled{false};
        bool coldEnabled{false};
        int hungerLevel{0};
        int exhaustionLevel{0};
        int coldLevel{0};
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

    bool ParseIntegrationEnabled(const std::string& configResponse, const std::string& integrationId)
    {
        auto idPos = configResponse.find("\"integration_id\":\"" + integrationId + "\"");
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

    RE::SpellItem* LookupPluginSpell(RE::TESDataHandler* dataHandler, RE::FormID localFormId, const char* pluginName)
    {
        if (!dataHandler || !pluginName) {
            return nullptr;
        }
        return dataHandler->LookupForm<RE::SpellItem>(localFormId, pluginName);
    }

    RE::TESGlobal* LookupGlobal(RE::TESDataHandler* dataHandler, RE::FormID localFormId, const char* pluginName)
    {
        if (!dataHandler || !pluginName) {
            return nullptr;
        }
        return dataHandler->LookupForm<RE::TESGlobal>(localFormId, pluginName);
    }

    int ClampNeedLevel(float value)
    {
        return std::max(0, std::min(5, static_cast<int>(std::lround(value))));
    }

    float GlobalValue(RE::TESGlobal* global, float fallback = 0.0f)
    {
        return global ? global->value : fallback;
    }

    bool GlobalEnabled(RE::TESGlobal* global, bool fallback = false)
    {
        return global ? global->value > 0.5f : fallback;
    }

    int ClampStageLevel(float value)
    {
        return std::max(0, std::min(5, static_cast<int>(std::lround(value))));
    }

    bool ActorHasSpell(RE::Actor* actor, RE::SpellItem* spell)
    {
        if (!actor || !spell) {
            return false;
        }

        try {
            return actor->HasSpell(spell);
        } catch (...) {
            return false;
        }
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

    BathingInSkyrimState ReadBathingInSkyrimState(RE::Actor* actor)
    {
        BathingInSkyrimState state;
        if (!actor) {
            return state;
        }

        auto* dataHandler = RE::TESDataHandler::GetSingleton();
        if (!dataHandler) {
            return state;
        }

        auto* enabledGlobal = LookupGlobal(dataHandler, 0x00000C, kBathingInSkyrimPlugin);
        std::vector<RE::SpellItem*> tierSpells = {
            LookupPluginSpell(dataHandler, 0x000043, kBathingInSkyrimPlugin),
            LookupPluginSpell(dataHandler, 0x000044, kBathingInSkyrimPlugin),
            LookupPluginSpell(dataHandler, 0x000045, kBathingInSkyrimPlugin),
            LookupPluginSpell(dataHandler, 0x000046, kBathingInSkyrimPlugin),
            LookupPluginSpell(dataHandler, 0x00006D, kBathingInSkyrimPlugin),
        };
        auto* bathingSpell = LookupPluginSpell(dataHandler, 0x00003D, kBathingInSkyrimPlugin);
        auto* soapySpell = LookupPluginSpell(dataHandler, 0x000039, kBathingInSkyrimPlugin);
        auto* soapyAnimatedSpell = LookupPluginSpell(dataHandler, 0x00003B, kBathingInSkyrimPlugin);

        state.available = enabledGlobal || bathingSpell || soapySpell || soapyAnimatedSpell;
        for (auto* spell : tierSpells) {
            state.available = state.available || spell;
        }
        if (!state.available) {
            return state;
        }

        state.enabled = GlobalEnabled(enabledGlobal, true);
        for (std::size_t i = 0; i < tierSpells.size(); ++i) {
            if (ActorHasSpell(actor, tierSpells[i])) {
                state.dirtinessTier = static_cast<int>(i);
            }
        }
        state.isBathing = ActorHasSpell(actor, bathingSpell);
        state.isSoapy = ActorHasSpell(actor, soapySpell) || ActorHasSpell(actor, soapyAnimatedSpell);
        return state;
    }

    SunHelmState ReadSunHelmState()
    {
        SunHelmState state;

        auto* dataHandler = RE::TESDataHandler::GetSingleton();
        if (!dataHandler) {
            return state;
        }

        auto* survivalToggle = LookupGlobal(dataHandler, 0x00A9AD94, kSunHelmPlugin);
        auto* hungerLevel = LookupGlobal(dataHandler, 0x0000EAAE, kSunHelmPlugin);
        auto* thirstLevel = LookupGlobal(dataHandler, 0x0005C472, kSunHelmPlugin);
        auto* exhaustionLevel = LookupGlobal(dataHandler, 0x00021E3F, kSunHelmPlugin);
        auto* coldLevel = LookupGlobal(dataHandler, 0x006A13C5, kSunHelmPlugin);
        auto* hungerDisabled = LookupGlobal(dataHandler, 0x00752707, kSunHelmPlugin);
        auto* thirstDisabled = LookupGlobal(dataHandler, 0x00752708, kSunHelmPlugin);
        auto* exhaustionDisabled = LookupGlobal(dataHandler, 0x00752709, kSunHelmPlugin);
        auto* coldDisabled = LookupGlobal(dataHandler, 0x0075270A, kSunHelmPlugin);
        auto* coldActive = LookupGlobal(dataHandler, 0x0079441D, kSunHelmPlugin);
        auto* coldForceDisabled = LookupGlobal(dataHandler, 0x0083132C, kSunHelmPlugin);

        state.available = survivalToggle || hungerLevel || thirstLevel || exhaustionLevel || coldLevel;
        if (!state.available) {
            return state;
        }

        state.enabled = GlobalEnabled(survivalToggle, true);
        state.hungerEnabled = state.enabled && !GlobalEnabled(hungerDisabled);
        state.thirstEnabled = state.enabled && !GlobalEnabled(thirstDisabled);
        state.exhaustionEnabled = state.enabled && !GlobalEnabled(exhaustionDisabled);
        state.coldEnabled = state.enabled && GlobalEnabled(coldActive, true) && !GlobalEnabled(coldDisabled) && !GlobalEnabled(coldForceDisabled);
        state.hungerLevel = ClampNeedLevel(GlobalValue(hungerLevel));
        state.thirstLevel = ClampNeedLevel(GlobalValue(thirstLevel));
        state.exhaustionLevel = ClampNeedLevel(GlobalValue(exhaustionLevel));
        state.coldLevel = ClampNeedLevel(GlobalValue(coldLevel));

        return state;
    }

    StarfrostState ReadStarfrostState(RE::Actor* actor)
    {
        StarfrostState state;
        if (!actor) {
            return state;
        }

        auto* dataHandler = RE::TESDataHandler::GetSingleton();
        if (!dataHandler) {
            return state;
        }

        auto* survivalModeEnabled = LookupGlobal(dataHandler, 0x000826, kSurvivalModePlugin);
        auto* hungerStarted = LookupGlobal(dataHandler, 0x000860, kStarfrostPlugin);
        auto* exhaustionStage = LookupGlobal(dataHandler, 0x000A1C, kSurvivalModeImprovedPlugin);
        auto* coldStage = LookupGlobal(dataHandler, 0x000D1E, kSurvivalModeImprovedPlugin);
        auto* exhaustionEnabled = LookupGlobal(dataHandler, 0x000F29, kSurvivalModeImprovedPlugin);
        auto* coldEnabled = LookupGlobal(dataHandler, 0x000F28, kSurvivalModeImprovedPlugin);

        auto* hungerSpell1 = LookupPluginSpell(dataHandler, 0x00084E, kStarfrostPlugin);
        auto* hungerSpell2 = LookupPluginSpell(dataHandler, 0x000856, kStarfrostPlugin);
        auto* hungerSpell3 = LookupPluginSpell(dataHandler, 0x000857, kStarfrostPlugin);

        state.available = survivalModeEnabled || hungerStarted || exhaustionStage || coldStage || hungerSpell1 || hungerSpell2 || hungerSpell3;
        if (!state.available) {
            return state;
        }

        state.enabled = GlobalEnabled(survivalModeEnabled);
        state.hungerLevel = 0;
        if (ActorHasSpell(actor, hungerSpell3)) {
            state.hungerLevel = 3;
        } else if (ActorHasSpell(actor, hungerSpell2)) {
            state.hungerLevel = 2;
        } else if (ActorHasSpell(actor, hungerSpell1)) {
            state.hungerLevel = 1;
        }

        state.hungerEnabled = state.enabled && (GlobalEnabled(hungerStarted) || state.hungerLevel > 0);
        state.exhaustionEnabled = state.enabled && GlobalEnabled(exhaustionEnabled, true);
        state.coldEnabled = state.enabled && GlobalEnabled(coldEnabled, true);
        state.exhaustionLevel = ClampStageLevel(GlobalValue(exhaustionStage));
        state.coldLevel = ClampStageLevel(GlobalValue(coldStage));

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

    std::string BuildBathingInSkyrimStatePayload(RE::PlayerCharacter* player, const BathingInSkyrimState& state)
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
        body << "\"integration_id\":\"bathing_in_skyrim\",";
        body << "\"actor_name\":\"" << JsonEscape(playerName) << "\",";
        body << "\"actor_type\":\"player\",";
        body << "\"runtime_formid\":\"" << FormIdHex(player ? player->GetFormID() : 0) << "\",";
        body << "\"gamets\":0,";
        body << "\"state\":{";
        body << "\"source_mod\":\"" << kBathingInSkyrimPlugin << "\",";
        body << "\"enabled\":" << (state.enabled ? "true" : "false") << ",";
        body << "\"dirtiness_tier\":" << state.dirtinessTier << ",";
        body << "\"is_dirty\":" << (state.dirtinessTier >= 2 ? "true" : "false") << ",";
        body << "\"is_very_dirty\":" << (state.dirtinessTier >= 3 ? "true" : "false") << ",";
        body << "\"is_bathing\":" << (state.isBathing ? "true" : "false") << ",";
        body << "\"is_soapy\":" << (state.isSoapy ? "true" : "false");
        body << "}";
        body << "}";
        body << "]";
        body << "}";
        return body.str();
    }

    std::string BuildSunHelmStatePayload(RE::PlayerCharacter* player, const SunHelmState& state)
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
        body << "\"integration_id\":\"sunhelm_survival\",";
        body << "\"actor_name\":\"" << JsonEscape(playerName) << "\",";
        body << "\"actor_type\":\"player\",";
        body << "\"runtime_formid\":\"" << FormIdHex(player ? player->GetFormID() : 0) << "\",";
        body << "\"gamets\":0,";
        body << "\"state\":{";
        body << "\"source_mod\":\"" << kSunHelmPlugin << "\",";
        body << "\"enabled\":" << (state.enabled ? "true" : "false") << ",";
        body << "\"hunger_enabled\":" << (state.hungerEnabled ? "true" : "false") << ",";
        body << "\"thirst_enabled\":" << (state.thirstEnabled ? "true" : "false") << ",";
        body << "\"exhaustion_enabled\":" << (state.exhaustionEnabled ? "true" : "false") << ",";
        body << "\"cold_enabled\":" << (state.coldEnabled ? "true" : "false") << ",";
        body << "\"hunger_level\":" << state.hungerLevel << ",";
        body << "\"thirst_level\":" << state.thirstLevel << ",";
        body << "\"exhaustion_level\":" << state.exhaustionLevel << ",";
        body << "\"cold_level\":" << state.coldLevel;
        body << "}";
        body << "}";
        body << "]";
        body << "}";
        return body.str();
    }

    std::string BuildStarfrostStatePayload(RE::PlayerCharacter* player, const StarfrostState& state)
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
        body << "\"integration_id\":\"starfrost_survival\",";
        body << "\"actor_name\":\"" << JsonEscape(playerName) << "\",";
        body << "\"actor_type\":\"player\",";
        body << "\"runtime_formid\":\"" << FormIdHex(player ? player->GetFormID() : 0) << "\",";
        body << "\"gamets\":0,";
        body << "\"state\":{";
        body << "\"source_mod\":\"" << kStarfrostPlugin << "\",";
        body << "\"enabled\":" << (state.enabled ? "true" : "false") << ",";
        body << "\"hunger_enabled\":" << (state.hungerEnabled ? "true" : "false") << ",";
        body << "\"exhaustion_enabled\":" << (state.exhaustionEnabled ? "true" : "false") << ",";
        body << "\"cold_enabled\":" << (state.coldEnabled ? "true" : "false") << ",";
        body << "\"hunger_level\":" << state.hungerLevel << ",";
        body << "\"exhaustion_level\":" << state.exhaustionLevel << ",";
        body << "\"cold_level\":" << state.coldLevel;
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
        bool bathingInSkyrimEnabled = true;
        bool sunHelmEnabled = true;
        bool starfrostEnabled = true;
        bool loggedDirtAndBloodAvailable = false;
        bool loggedBathingInSkyrimAvailable = false;
        bool loggedSunHelmAvailable = false;
        bool loggedStarfrostAvailable = false;
        std::string lastDirtAndBloodHash;
        std::string lastBathingInSkyrimHash;
        std::string lastSunHelmHash;
        std::string lastStarfrostHash;
        auto nextConfigRefresh = std::chrono::steady_clock::now();
        auto nextHeartbeatPost = std::chrono::steady_clock::now();

        while (g_monitorRunning.load()) {
            ServerSettings settings = LoadServerSettings();
            auto now = std::chrono::steady_clock::now();

            if (now >= nextConfigRefresh) {
                std::string configResponse;
                if (HttpRequest(settings, "GET", settings.configPath, "", &configResponse)) {
                    dirtAndBloodEnabled = ParseIntegrationEnabled(configResponse, "dirt_and_blood");
                    bathingInSkyrimEnabled = ParseIntegrationEnabled(configResponse, "bathing_in_skyrim");
                    sunHelmEnabled = ParseIntegrationEnabled(configResponse, "sunhelm_survival");
                    starfrostEnabled = ParseIntegrationEnabled(configResponse, "starfrost_survival");
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
                    if (!loggedDirtAndBloodAvailable) {
                        logger::info("[CHIM-Custom] Dirt and Blood detected");
                        loggedDirtAndBloodAvailable = true;
                    }

                    std::string hash = std::to_string(state.dirtLevel) + "|" +
                        std::to_string(state.bloodLevel) + "|" +
                        (state.isClean ? "1" : "0") + "|" +
                        (state.isWashing ? "1" : "0");

                    if (hash != lastDirtAndBloodHash) {
                        lastDirtAndBloodHash = hash;
                        HttpRequest(settings, "POST", settings.statePath, BuildStatePayload(player, state), nullptr);
                        nextHeartbeatPost = std::chrono::steady_clock::now() + std::chrono::seconds(60);
                    }
                }
            }

            if (player && bathingInSkyrimEnabled) {
                BathingInSkyrimState state = ReadBathingInSkyrimState(player);
                if (state.available) {
                    if (!loggedBathingInSkyrimAvailable) {
                        logger::info("[CHIM-Custom] Bathing in Skyrim detected");
                        loggedBathingInSkyrimAvailable = true;
                    }

                    std::string hash = std::string(state.enabled ? "1" : "0") + "|" +
                        std::to_string(state.dirtinessTier) + "|" +
                        (state.isBathing ? "1" : "0") + "|" +
                        (state.isSoapy ? "1" : "0");

                    if (hash != lastBathingInSkyrimHash) {
                        lastBathingInSkyrimHash = hash;
                        HttpRequest(settings, "POST", settings.statePath, BuildBathingInSkyrimStatePayload(player, state), nullptr);
                        nextHeartbeatPost = std::chrono::steady_clock::now() + std::chrono::seconds(60);
                    }
                }
            }

            if (player && sunHelmEnabled) {
                SunHelmState state = ReadSunHelmState();
                if (state.available) {
                    if (!loggedSunHelmAvailable) {
                        logger::info("[CHIM-Custom] SunHelm Survival detected");
                        loggedSunHelmAvailable = true;
                    }

                    std::string hash = std::string(state.enabled ? "1" : "0") + "|" +
                        (state.hungerEnabled ? "1" : "0") + "|" +
                        (state.thirstEnabled ? "1" : "0") + "|" +
                        (state.exhaustionEnabled ? "1" : "0") + "|" +
                        (state.coldEnabled ? "1" : "0") + "|" +
                        std::to_string(state.hungerLevel) + "|" +
                        std::to_string(state.thirstLevel) + "|" +
                        std::to_string(state.exhaustionLevel) + "|" +
                        std::to_string(state.coldLevel);

                    if (hash != lastSunHelmHash) {
                        lastSunHelmHash = hash;
                        HttpRequest(settings, "POST", settings.statePath, BuildSunHelmStatePayload(player, state), nullptr);
                        nextHeartbeatPost = std::chrono::steady_clock::now() + std::chrono::seconds(60);
                    }
                }
            }

            if (player && starfrostEnabled) {
                StarfrostState state = ReadStarfrostState(player);
                if (state.available) {
                    if (!loggedStarfrostAvailable) {
                        logger::info("[CHIM-Custom] Starfrost Survival detected");
                        loggedStarfrostAvailable = true;
                    }

                    std::string hash = std::string(state.enabled ? "1" : "0") + "|" +
                        (state.hungerEnabled ? "1" : "0") + "|" +
                        (state.exhaustionEnabled ? "1" : "0") + "|" +
                        (state.coldEnabled ? "1" : "0") + "|" +
                        std::to_string(state.hungerLevel) + "|" +
                        std::to_string(state.exhaustionLevel) + "|" +
                        std::to_string(state.coldLevel);

                    if (hash != lastStarfrostHash) {
                        lastStarfrostHash = hash;
                        HttpRequest(settings, "POST", settings.statePath, BuildStarfrostStatePayload(player, state), nullptr);
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
