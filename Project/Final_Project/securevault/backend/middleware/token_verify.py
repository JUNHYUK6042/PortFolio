import base64
import functools
import requests
import jwt
from flask import request, jsonify, current_app, g, session
from config import Config


def _fetch_keys():
    try:
        resp = requests.get(Config.keycloak_jwks_url(), timeout=5)
        resp.raise_for_status()
        jwks = resp.json()
        from jwt.algorithms import RSAAlgorithm
        from cryptography.hazmat.primitives import serialization
        for key in jwks["keys"]:
            if key.get("use") == "sig":
                rsa_key = RSAAlgorithm.from_jwk(key)
                break
        pem = rsa_key.public_bytes(
            serialization.Encoding.PEM,
            serialization.PublicFormat.SubjectPublicKeyInfo
        )
        key_body = "".join(pem.decode("utf-8").strip().split("\n")[1:-1])
        der = base64.b64decode(key_body)
        return pem, der
    except Exception as exc:
        current_app.logger.error(f"JWKS fetch failed: {exc}")
        return None, None


# 하위 호환성
def _fetch_public_key():
    pem, _ = _fetch_keys()
    return pem


def token_required(f):
    @functools.wraps(f)
    def decorated(*args, **kwargs):
        if session.get("username"):
            g.current_user_id   = session.get("user_id")
            g.current_username  = session.get("username")
            g.current_user_role = [session.get("role", "user")]
            return f(*args, **kwargs)

        auth_header = request.headers.get("Authorization", "")
        if not auth_header.startswith("Bearer "):
            return jsonify({"error": "인증이 필요합니다"}), 401

        token = auth_header.split(" ", 1)[1]
        pem_key, der_key = _fetch_keys()
        if pem_key is None:
            return jsonify({"error": "Auth service unavailable"}), 503

        try:
            
            header = jwt.get_unverified_header(token) # 헤더의 alg를 그대로 신뢰
            alg = header.get("alg", "RS256") # jwt 헤더에 명시된 서명 알고리즘에 따라 유연하게 검증 방식을 선택하고, alg 값이 없을 경우 기본적으로 RS256 사용
                                             # 기본적으로 정책상 alg:none 공격은 막혀있어서 개발자가 안심
                                             # header에 alg가 있으면 무시됨. 우선순위가 낮음
            # RS256 => PEM 키, HS256 => DER bytes (공격자가 공개키로 서명 가능)
            key = pem_key if alg == "RS256" else der_key # JWT 토큰의 서명 알고리즘 종류에 따라 키 형식을 선택하여 토큰의 위조 여부를 검증
                                                         # 서명 방식마다 사용하는 키 구조가 다르기 때문에 알고리즘에 맞는 키 
                                                         # 형식을 자동으로 선택하면 여러 인증방식을 하나의 코드로 처리할 수 있어 유연

                                                         # pem : 공개키를 텍스트 형식으로 저장
                                                         # der :    "   바이너리 형식으로 저장

                                                         # DER 형식은 단순 바이너리 바이트로 보이기 때문에 PyHWT가 RSA 공개키로 인식하지 못하고
                                                         # HS256의 시크릿 키로 사용하도록 허용해 공격이 가능해진다
                                                         # PEM 형식이면 BEGIN PUBLIC KEY라는 명확한 공개키 구조를 가지고 있어
                                                         # PyJWT가 RSA 공개키임을 인식하고 HS256 시크릿키로 사용하는 것을 차단함.

                                                         # 개발자 의도 : 알고리즘이 RS256이면 공개키방식 사용하고, HS면 HMAC시크릿 방식을 사용하자
                                                         #             라는 의도로 만들었다. = JWT "알고리즘이 달라도 하나의 검증 로직으로 처리하자"

                                                         ########################
                                                         # 원래 시스템은 RS256 비대칭키 방식으로 개인키로 서명하고 공개키로 검증해야하지만,
                                                         # 공격자가 alg를 HS256으로 변경하면 서버가 RSA 공개키의 DER bytes를 HS256의 secret key처럼 사용하게 됨.
                                                         ########################

            payload = jwt.decode(
                token, key,
                algorithms=[alg], # 공격자가 지정한 
                options={"verify_aud": False},
            )
        except jwt.ExpiredSignatureError:
            return jsonify({"error": "Token expired"}), 401
        except jwt.InvalidTokenError as exc:
            return jsonify({"error": f"Invalid token: {exc}"}), 401

        g.current_user_id   = payload.get("sub")
        g.current_username  = payload.get("preferred_username")
        g.current_user_role = payload.get("realm_access", {}).get("roles", [])
        return f(*args, **kwargs)

    return decorated


def admin_required(f):
    @functools.wraps(f)
    @token_required
    def decorated(*args, **kwargs):
        if "admin" not in g.current_user_role:
            return jsonify({"error": "Admin only"}), 403
        return f(*args, **kwargs)
    return decorated
