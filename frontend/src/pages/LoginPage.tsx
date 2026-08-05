import { standardSchemaResolver } from '@hookform/resolvers/standard-schema'
import { isAxiosError } from 'axios'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { Navigate, useNavigate } from 'react-router-dom'

import { apiErrorKey } from '@/api/errors'
import { Button } from '@/components/Button'
import { Input } from '@/components/Input'
import { loginSchema, type LoginGirdisi } from '@/features/auth/schemas/loginSchema'
import { useAuth } from '@/hooks/useAuth'

export function LoginPage() {
  const { t } = useTranslation()
  const { user, login } = useAuth()
  const navigate = useNavigate()

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<LoginGirdisi>({
    resolver: standardSchemaResolver(loginSchema),
  })

  if (user) {
    return <Navigate to="/" replace />
  }

  const onSubmit = handleSubmit(async (girdi) => {
    try {
      await login(girdi)
      navigate('/', { replace: true })
    } catch (error) {
      // Backend'in alan hataları (422 hatalar) zaten yerelleşmiş metindir, olduğu gibi gösterilir;
      // diğer hatalar makine okunur kod üzerinden i18n'e çevrilir (api/errors.ts)
      let mesaj = t(apiErrorKey(error))
      if (isAxiosError(error) && error.response?.status === 422) {
        const govde = error.response.data as { hatalar?: Record<string, string[]> }
        mesaj = Object.values(govde.hatalar ?? {})[0]?.[0] ?? mesaj
      }
      setError('root', { message: mesaj })
    }
  })

  return (
    <div className="w-full max-w-md rounded-xl border border-slate-200 bg-white p-8 shadow-sm dark:border-slate-800 dark:bg-slate-900">
      <h1 className="text-center text-2xl font-bold text-blue-600 dark:text-blue-400">
        {t('ortak.uygulamaAdi')}
      </h1>
      <p className="mt-1 text-center text-sm text-slate-500 dark:text-slate-400">
        {t('giris.altBaslik')}
      </p>

      <form onSubmit={(e) => void onSubmit(e)} className="mt-8 space-y-4" noValidate>
        <Input
          id="kullanici-adi"
          label={t('giris.kullaniciAdi')}
          autoComplete="username"
          hata={errors.kullanici_adi?.message ? t(errors.kullanici_adi.message) : undefined}
          {...register('kullanici_adi')}
        />
        <Input
          id="sifre"
          type="password"
          label={t('giris.sifre')}
          autoComplete="current-password"
          hata={errors.sifre?.message ? t(errors.sifre.message) : undefined}
          {...register('sifre')}
        />

        {errors.root?.message ? (
          <p className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
            {errors.root.message}
          </p>
        ) : null}

        <Button type="submit" yukleniyor={isSubmitting} className="w-full">
          {isSubmitting ? t('giris.girisYapiliyor') : t('giris.girisYap')}
        </Button>
      </form>
    </div>
  )
}
