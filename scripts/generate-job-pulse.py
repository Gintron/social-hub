#!/usr/bin/env python3
"""Make the original, loopable music bed for job videos. Uses only Python's standard library.

Run: python3 scripts/generate-job-pulse.py /tmp/studentski-pulse.wav
Then encode with: ffmpeg -i /tmp/studentski-pulse.wav -c:a aac -b:a 160k resources/audio/studentski-pulse.m4a
"""

import math
import random
import sys
import wave
from array import array

RATE = 22050
BPM = 112
BEAT = 60 / BPM
CHORDS = [
    (130.81, (261.63, 329.63, 392.00)),  # C
    (98.00, (196.00, 246.94, 293.66)),   # G
    (110.00, (220.00, 261.63, 329.63)),  # Am
    (87.31, (174.61, 220.00, 261.63)),   # F
] * 2
DURATION = len(CHORDS) * 4 * BEAT
SAMPLES = [0.0] * int(DURATION * RATE)
NOISE = random.Random(26928)


def mix_tone(start, duration, frequency, volume, decay=0.0, pad=False):
    first = int(start * RATE)
    count = min(int(duration * RATE), len(SAMPLES) - first)
    for i in range(count):
        t = i / RATE
        envelope = min(1.0, t / 0.015) if pad else min(1.0, t / 0.006)
        envelope *= min(1.0, (duration - t) / (0.16 if pad else 0.045))
        if decay:
            envelope *= math.exp(-decay * t)
        phase = 2 * math.pi * frequency * t
        tone = math.sin(phase) + (0.28 if pad else 0.20) * math.sin(2 * phase)
        SAMPLES[first + i] += volume * envelope * tone


def mix_kick(start):
    first = int(start * RATE)
    for i in range(min(int(0.32 * RATE), len(SAMPLES) - first)):
        t = i / RATE
        phase = 2 * math.pi * (52 * t + 83 * (1 - math.exp(-20 * t)) / 20)
        SAMPLES[first + i] += 0.21 * math.exp(-16 * t) * math.sin(phase)


def mix_snare(start):
    first = int(start * RATE)
    for i in range(min(int(0.17 * RATE), len(SAMPLES) - first)):
        t = i / RATE
        sound = NOISE.uniform(-1, 1) * 0.11 + math.sin(2 * math.pi * 172 * t) * 0.045
        SAMPLES[first + i] += math.exp(-24 * t) * sound


def mix_hat(start, volume):
    first = int(start * RATE)
    for i in range(min(int(0.065 * RATE), len(SAMPLES) - first)):
        t = i / RATE
        SAMPLES[first + i] += volume * math.exp(-65 * t) * NOISE.uniform(-1, 1)


for bar, (root, notes) in enumerate(CHORDS):
    bar_start = bar * 4 * BEAT
    for note in notes:
        mix_tone(bar_start, 4 * BEAT, note, 0.027, pad=True)
    for beat in range(4):
        when = bar_start + beat * BEAT
        if beat % 2 == 0:
            mix_kick(when)
        else:
            mix_snare(when)
        mix_tone(when, 0.42, root, 0.095, decay=5.5)
        for half in range(2):
            mix_hat(when + half * BEAT / 2, 0.11 if half == 0 else 0.08)
            note = notes[(beat * 2 + half) % 3] * (2 if half == 1 else 1)
            mix_tone(when + half * BEAT / 2, 0.31, note, 0.054, decay=7.0)

peak = max(abs(value) for value in SAMPLES)
scale = 0.78 / max(peak, 0.78)
pcm = array("h")
for i, value in enumerate(SAMPLES):
    edge = min(1.0, i / (RATE * 0.16), (len(SAMPLES) - i) / (RATE * 0.20))
    pcm.append(int(max(-1.0, min(1.0, value * scale * edge)) * 32767))

with wave.open(sys.argv[1], "wb") as output:
    output.setnchannels(1)
    output.setsampwidth(2)
    output.setframerate(RATE)
    output.writeframes(pcm.tobytes())
