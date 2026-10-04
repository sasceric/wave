<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { MessageCircle, Send } from '@lucide/vue'
import LocalizedLink from '../components/shared/LocalizedLink.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import { currentUser } from '../composables/useCurrentUser'
import { apiGet, apiRequest, formatDate } from '../lib/api'
import { localizedRouteName } from '../routePaths'

const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()
const conversations = ref([])
const conversationMessages = ref([])
const pendingDetails = ref(null)
const selectedConversationId = ref(null)
const draft = ref('')
const loading = ref(true)
const sending = ref(false)
const error = ref('')
const authRequired = ref(false)
const messageList = ref(null)
let refreshTimer = null

const pendingStart = computed(() => {
  const campaign = route.query.campaign
  const creatorId = Number(route.query.creatorId)
  const creatorSlug = route.query.creatorSlug
  if (
    typeof campaign !== 'string'
    || !campaign
    || !Number.isInteger(creatorId)
    || creatorId < 1
    || typeof creatorSlug !== 'string'
    || !creatorSlug
  ) {
    return null
  }

  return { campaign, creatorId, creatorSlug }
})

const selectedConversation = computed(() => conversations.value.find(
  (conversation) => conversation.id === selectedConversationId.value,
) || null)

const canSend = computed(() => (
  !sending.value
  && draft.value.trim().length > 0
  && draft.value.trim().length <= 2000
  && (selectedConversation.value || (
    pendingStart.value
    && currentUser.value?.accountType === 'company'
    && pendingDetails.value
  ))
))

onMounted(() => {
  void loadInbox()
  refreshTimer = window.setInterval(() => {
    void refreshInbox()
  }, 20000)
})

onBeforeUnmount(() => {
  if (refreshTimer !== null) {
    window.clearInterval(refreshTimer)
  }
})

async function loadInbox() {
  loading.value = true
  error.value = ''
  authRequired.value = false
  try {
    const response = await apiGet('/me/conversations')
    conversations.value = response.data

    const requestedId = Number(route.query.conversation)
    const requestedConversation = conversations.value.find((item) => item.id === requestedId)
    if (requestedConversation) {
      await selectConversation(requestedConversation.id, false)
      return
    }

    if (pendingStart.value) {
      const existing = conversations.value.find((item) => (
        item.campaign.slug === pendingStart.value.campaign
        && item.creator.id === pendingStart.value.creatorId
      ))
      if (existing) {
        await selectConversation(existing.id)
      } else if (currentUser.value?.accountType === 'company') {
        await loadPendingDetails()
      }
      return
    }

    if (conversations.value.length) {
      await selectConversation(conversations.value[0].id)
    } else {
      selectedConversationId.value = null
      conversationMessages.value = []
    }
  } catch (cause) {
    handleLoadError(cause)
  } finally {
    loading.value = false
  }
}

async function loadPendingDetails() {
  try {
    const target = pendingStart.value
    const [campaignResponse, creatorResponse] = await Promise.all([
      apiGet(`/campaigns/${encodeURIComponent(target.campaign)}`),
      apiGet(`/creators/${encodeURIComponent(target.creatorSlug)}`),
    ])
    pendingDetails.value = {
      campaign: campaignResponse.data,
      creator: creatorResponse.data,
    }
  } catch (cause) {
    error.value = cause.message
  }
}

async function selectConversation(id, syncRoute = true) {
  selectedConversationId.value = id
  pendingDetails.value = null
  if (syncRoute) {
    await router.replace({
      name: localizedRouteName('messages', locale.value),
      query: { conversation: id },
    })
  }
  await loadMessages(id)
}

async function loadMessages(id) {
  try {
    const response = await apiGet(`/me/conversations/${id}/messages`)
    conversationMessages.value = response.data
    upsertConversation(response.conversation)
    await nextTick()
    if (messageList.value) {
      messageList.value.scrollTop = messageList.value.scrollHeight
    }
  } catch (cause) {
    handleLoadError(cause)
  }
}

async function refreshInbox() {
  try {
    const response = await apiGet('/me/conversations')
    conversations.value = response.data
    if (selectedConversationId.value !== null) {
      await loadMessages(selectedConversationId.value)
    }
  } catch (cause) {
    if (cause.status !== 401) {
      error.value = cause.message
    }
  }
}

function upsertConversation(conversation) {
  const existingIndex = conversations.value.findIndex((item) => item.id === conversation.id)
  if (existingIndex === -1) {
    conversations.value.unshift(conversation)
    return
  }
  conversations.value.splice(existingIndex, 1, conversation)
}

async function sendMessage() {
  if (!canSend.value) {
    return
  }
  sending.value = true
  error.value = ''
  const body = draft.value.trim()
  try {
    if (pendingStart.value && !selectedConversation.value) {
      const target = pendingStart.value
      const response = await apiRequest(
        `/company/campaigns/${encodeURIComponent(target.campaign)}/conversations`,
        {
          method: 'POST',
          body: { creatorId: target.creatorId, message: body },
        },
      )
      upsertConversation(response.data)
      conversationMessages.value = [response.message]
      selectedConversationId.value = response.data.id
      pendingDetails.value = null
      await router.replace({
        name: localizedRouteName('messages', locale.value),
        query: { conversation: response.data.id },
      })
    } else if (selectedConversationId.value !== null) {
      const response = await apiRequest(
        `/me/conversations/${selectedConversationId.value}/messages`,
        { method: 'POST', body: { body } },
      )
      conversationMessages.value.push(response.data)
      const conversation = selectedConversation.value
      if (conversation) {
        upsertConversation({
          ...conversation,
          lastMessage: body,
          lastMessageAt: response.data.createdAt,
          lastMessageSenderId: currentUser.value?.id,
          unreadCount: 0,
        })
      }
      await nextTick()
      if (messageList.value) {
        messageList.value.scrollTop = messageList.value.scrollHeight
      }
    }
    draft.value = ''
  } catch (cause) {
    error.value = cause.message
  } finally {
    sending.value = false
  }
}

function handleLoadError(cause) {
  if (cause.status === 401) {
    authRequired.value = true
    conversations.value = []
    conversationMessages.value = []
    return
  }
  error.value = cause.message
}

function conversationName(conversation) {
  return currentUser.value?.accountType === 'company'
    ? conversation.creator.displayName
    : conversation.company.name
}
</script>

<template>
  <section class="page-width campaign-messages">
    <header class="campaign-messages__heading">
      <div>
        <p class="eyebrow">{{ t('campaignChat.inbox') }}</p>
        <h1>{{ t('campaignChat.title') }}</h1>
        <p>{{ t('campaignChat.subtitle') }}</p>
      </div>
      <MessageCircle :size="28" stroke-width="1.5" aria-hidden="true" />
    </header>

    <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
    <div v-if="authRequired" class="campaign-messages__signed-out">
      <p>{{ t('campaignChat.loginRequired') }}</p>
      <LocalizedLink class="button button--dark" :to="{ name: 'account', query: { mode: 'login' } }">
        {{ t('auth.signIn') }}
      </LocalizedLink>
    </div>
    <StatusMessage v-else-if="loading" variant="loading">{{ t('campaignChat.loading') }}</StatusMessage>
    <div v-else class="campaign-messages__layout">
      <aside class="campaign-messages__inbox">
        <h2>{{ t('campaignChat.inbox') }}</h2>
        <StatusMessage v-if="!conversations.length && !pendingStart" variant="empty">
          {{ t('campaignChat.noConversations') }}
        </StatusMessage>
        <button
          v-for="conversation in conversations"
          :key="conversation.id"
          class="campaign-messages__conversation"
          :class="{ 'is-active': conversation.id === selectedConversationId }"
          type="button"
          @click="selectConversation(conversation.id)"
        >
          <span class="campaign-messages__conversation-top">
            <strong>{{ conversationName(conversation) }}</strong>
            <span v-if="conversation.unreadCount" class="campaign-messages__unread">
              {{ conversation.unreadCount }}
            </span>
          </span>
          <span class="campaign-messages__campaign">{{ conversation.campaign.title }}</span>
          <span class="campaign-messages__preview">{{ conversation.lastMessage }}</span>
        </button>
      </aside>

      <section class="campaign-messages__thread" aria-live="polite">
        <template v-if="selectedConversation">
          <header class="campaign-messages__thread-heading">
            <div>
              <h2>{{ conversationName(selectedConversation) }}</h2>
              <LocalizedLink
                :to="{ name: 'campaign-detail', params: { slug: selectedConversation.campaign.slug } }"
              >
                {{ selectedConversation.campaign.title }}
              </LocalizedLink>
            </div>
            <span>{{ t('campaignChat.campaign') }}</span>
          </header>
          <div
            ref="messageList"
            class="campaign-messages__timeline"
            :aria-label="t('campaignChat.title')"
          >
            <article
              v-for="message in conversationMessages"
              :key="message.id"
              class="campaign-messages__message"
              :class="{ 'campaign-messages__message--mine': message.senderId === currentUser?.id }"
            >
              <p>{{ message.body }}</p>
              <time :datetime="message.createdAt">{{ formatDate(message.createdAt) }}</time>
            </article>
          </div>
          <form class="campaign-messages__composer" @submit.prevent="sendMessage">
            <label class="form-field">
              <span class="visually-hidden">{{ t('campaignChat.replyPlaceholder') }}</span>
              <textarea
                v-model="draft"
                maxlength="2000"
                required
                :placeholder="t('campaignChat.replyPlaceholder')"
              ></textarea>
            </label>
            <button class="button button--dark" type="submit" :disabled="!canSend">
              <Send :size="16" aria-hidden="true" />
              {{ sending ? t('campaignChat.sending') : t('campaignChat.send') }}
            </button>
          </form>
        </template>

        <template v-else-if="pendingStart && pendingDetails && currentUser?.accountType === 'company'">
          <header class="campaign-messages__thread-heading">
            <div>
              <h2>{{ t('campaignChat.newConversationTitle') }}</h2>
              <p>{{ pendingDetails.creator.displayName }} · {{ pendingDetails.campaign.title }}</p>
            </div>
          </header>
          <p class="campaign-messages__empty">{{ t('campaignChat.newConversationDescription') }}</p>
          <form class="campaign-messages__composer" @submit.prevent="sendMessage">
            <label class="form-field">
              <span>{{ t('campaignChat.firstMessage') }}</span>
              <textarea
                v-model="draft"
                maxlength="2000"
                required
                :placeholder="t('campaignChat.replyPlaceholder')"
              ></textarea>
            </label>
            <button class="button button--dark" type="submit" :disabled="!canSend">
              <Send :size="16" aria-hidden="true" />
              {{ sending ? t('campaignChat.sending') : t('campaignChat.send') }}
            </button>
          </form>
        </template>

        <p
          v-else-if="pendingStart && currentUser && currentUser.accountType !== 'company'"
          class="campaign-messages__empty"
        >{{ t('campaignChat.companyOnlyStart') }}</p>
        <p v-else class="campaign-messages__empty">{{ t('campaignChat.selectConversation') }}</p>
      </section>
    </div>
  </section>
</template>
