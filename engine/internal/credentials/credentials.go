// Package credentials decrypts Eloquent `encrypted` values in memory with the
// same AES-256-CBC payload format as Illuminate\Encryption\Encrypter.
//
// The package never reads APP_KEY or .env, never logs a key, payload, or
// plaintext, and never serialises decrypted values. Callers pass the current
// key (and optional previous keys) from their own configuration. Use Redact
// before writing any diagnostic that might contain a secret.
package credentials

import (
	"crypto/aes"
	"crypto/cipher"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"strings"
)

// ErrMACInvalid is Laravel's DecryptException message.
var ErrMACInvalid = errors.New("The MAC is invalid.")

// ErrCouldNotDecrypt is Laravel's DecryptException message when the payload
// authenticates but the ciphertext cannot be opened.
var ErrCouldNotDecrypt = errors.New("Could not decrypt the data.")

// ErrPayloadInvalid is Laravel's DecryptException message for a malformed
// payload.
var ErrPayloadInvalid = errors.New("The payload is invalid.")

// ErrTagUnsupported is Laravel's CBC tag rejection.
var ErrTagUnsupported = errors.New("Unable to use tag because the cipher algorithm does not support AEAD.")

const redacted = "[REDACTED]"

type payload struct {
	IV    string
	Value string
	MAC   string
	Tag   string
}

// KeyBytes decodes a Laravel key. A "base64:" prefix is decoded; otherwise the
// bytes are used as supplied. AES-256-CBC requires exactly 32 bytes.
func KeyBytes(key string) ([]byte, error) {
	encoded := key
	if strings.HasPrefix(key, "base64:") {
		encoded = strings.TrimPrefix(key, "base64:")
	}

	decoded, err := decodeBase64(encoded)
	if err != nil {
		return nil, fmt.Errorf("invalid encryption key: %w", err)
	}

	if len(decoded) != 32 {
		return nil, fmt.Errorf("invalid encryption key length: got %d bytes, want 32", len(decoded))
	}

	return decoded, nil
}

// DecryptString decrypts a Crypt::encryptString payload without unserialising
// it. Previous keys are tried after the current key, exactly as Laravel's
// getAllKeys does, and the MAC is validated per key.
func DecryptString(ciphertext, key string, previousKeys ...string) (string, error) {
	current, err := KeyBytes(key)
	if err != nil {
		return "", err
	}

	keys := make([][]byte, 0, len(previousKeys)+1)
	keys = append(keys, current)

	for _, previous := range previousKeys {
		decoded, decodeErr := KeyBytes(previous)
		if decodeErr != nil {
			return "", decodeErr
		}

		keys = append(keys, decoded)
	}

	decodedPayload, err := decodePayload(ciphertext)
	if err != nil {
		return "", err
	}

	iv, err := decodeBase64(decodedPayload.IV)
	if err != nil || len(iv) != aes.BlockSize {
		return "", ErrPayloadInvalid
	}

	// Laravel treats an empty tag as absent; a non-empty tag is rejected for
	// CBC because the cipher has no AEAD support.
	if decodedPayload.Tag != "" {
		return "", ErrTagUnsupported
	}

	value, err := decodeBase64(decodedPayload.Value)
	if err != nil {
		// Laravel hands the payload value to openssl_decrypt, which fails and
		// surfaces "Could not decrypt the data." rather than a payload error.
		return "", ErrCouldNotDecrypt
	}

	foundValidMAC := false
	decrypted := false
	var plaintext string

	for _, candidate := range keys {
		if !validMAC(decodedPayload.IV, decodedPayload.Value, decodedPayload.MAC, candidate) {
			continue
		}

		foundValidMAC = true

		plain, ok := decryptCBC(value, candidate, iv)
		if ok {
			plaintext = plain
			decrypted = true
			break
		}
	}

	if !foundValidMAC {
		return "", ErrMACInvalid
	}

	if !decrypted {
		return "", ErrCouldNotDecrypt
	}

	return plaintext, nil
}

func decodePayload(ciphertext string) (payload, error) {
	raw, err := decodeBase64(ciphertext)
	if err != nil {
		return payload{}, ErrPayloadInvalid
	}

	var decoded map[string]any
	if err := json.Unmarshal(raw, &decoded); err != nil {
		return payload{}, ErrPayloadInvalid
	}

	iv, ivOK := decoded["iv"].(string)
	value, valueOK := decoded["value"].(string)
	mac, macOK := decoded["mac"].(string)
	if !ivOK || !valueOK || !macOK {
		return payload{}, ErrPayloadInvalid
	}

	tag := ""
	if rawTag, present := decoded["tag"]; present && rawTag != nil {
		typed, ok := rawTag.(string)
		if !ok {
			return payload{}, ErrPayloadInvalid
		}
		tag = typed
	}

	return payload{IV: iv, Value: value, MAC: mac, Tag: tag}, nil
}

func validMAC(iv, value, mac string, key []byte) bool {
	hash := hmac.New(sha256.New, key)
	hash.Write([]byte(iv + value))
	computed := hex.EncodeToString(hash.Sum(nil))

	return hmac.Equal([]byte(computed), []byte(mac))
}

func decryptCBC(value, key, iv []byte) (string, bool) {
	block, err := aes.NewCipher(key)
	if err != nil {
		return "", false
	}

	if len(value) == 0 || len(value)%aes.BlockSize != 0 {
		return "", false
	}

	plain := make([]byte, len(value))
	cipher.NewCBCDecrypter(block, iv).CryptBlocks(plain, value)

	unpadded, ok := unpadPKCS7(plain)
	if !ok {
		return "", false
	}

	return string(unpadded), true
}

func unpadPKCS7(value []byte) ([]byte, bool) {
	if len(value) == 0 {
		return nil, false
	}

	padding := int(value[len(value)-1])
	if padding == 0 || padding > aes.BlockSize || padding > len(value) {
		return nil, false
	}

	for _, b := range value[len(value)-padding:] {
		if int(b) != padding {
			return nil, false
		}
	}

	return value[:len(value)-padding], true
}

// decodeBase64 accepts standard and URL-safe base64, tolerates whitespace, and
// restores missing padding. PHP's base64_decode is similarly lenient.
func decodeBase64(value string) ([]byte, error) {
	value = strings.Map(func(r rune) rune {
		switch r {
		case ' ', '\n', '\r', '\t':
			return -1
		default:
			return r
		}
	}, value)

	if decoded, err := base64.StdEncoding.DecodeString(value); err == nil {
		return decoded, nil
	}

	trimmed := strings.TrimRight(value, "=")
	decoded, err := base64.RawStdEncoding.DecodeString(trimmed)
	if err == nil {
		return decoded, nil
	}

	return base64.RawURLEncoding.DecodeString(trimmed)
}

// Redact replaces every non-empty secret in text with [REDACTED]. Call it
// before logging a decryption error or any value derived from a credential.
func Redact(text string, secrets ...string) string {
	for _, secret := range secrets {
		if secret == "" {
			continue
		}

		text = strings.ReplaceAll(text, secret, redacted)
	}

	return text
}

// SafeError renders an error for logs with any supplied secrets redacted.
func SafeError(err error, secrets ...string) string {
	if err == nil {
		return ""
	}

	return Redact(err.Error(), secrets...)
}
