cmake_minimum_required(VERSION 3.21)

project(CHIMCustom VERSION 0.4.0)

find_package(SkyrimScripting.CMake CONFIG REQUIRED)
find_package(SkyrimScripting.Plugin CONFIG REQUIRED)

set(CHIMCUSTOM_SKYRIM_FOLDER_CANDIDATES
    "$ENV{CMAKE_SKYRIM_FOLDER}"
    "C:/Games/steamapps/common/Skyrim Special Edition"
    "C:/Program Files (x86)/Steam/steamapps/common/Skyrim Special Edition"
    "C:/Games/steamapps/common/SkyrimVR"
    "C:/Program Files (x86)/Steam/steamapps/common/SkyrimVR"
)

set(CHIMCUSTOM_SKYRIM_FOLDER "")
foreach(candidate IN LISTS CHIMCUSTOM_SKYRIM_FOLDER_CANDIDATES)
    if(candidate AND IS_DIRECTORY "${candidate}/Data")
        set(CHIMCUSTOM_SKYRIM_FOLDER "${candidate}")
        break()
    endif()
endforeach()

if(NOT CHIMCUSTOM_SKYRIM_FOLDER)
    message(FATAL_ERROR "Unable to locate a Skyrim install with a Data directory. Set CMAKE_SKYRIM_FOLDER or install Skyrim SE/VR in a known path.")
endif()

add_skse_plugin(${PROJECT_NAME}
    SKYRIM_FOLDER "${CHIMCUSTOM_SKYRIM_FOLDER}"
    SOURCES src/main.cpp
)

target_link_libraries(${PROJECT_NAME}
    PRIVATE
        SkyrimScriptingPlugin::SkyrimScripting.Plugin
        winhttp
)

target_compile_definitions(${PROJECT_NAME}
    PRIVATE
        WIN32_LEAN_AND_MEAN
        NOMINMAX
)
